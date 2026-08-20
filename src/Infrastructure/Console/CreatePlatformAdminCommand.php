<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusTransition;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusTransition;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Создание системного суперадминистратора (роль platform_admin).
 *
 * Предназначена для bootstrap'а установки (первичный доступ) — не для
 * повседневного управления пользователями (это делает UserManagementService
 * в рамках компании). Повторный запуск с тем же email — ошибка (пользователь
 * уже существует). Созданный пользователь сразу ACTIVE (emailVerifiedAt
 * проставлен) — подтверждение email не требуется (системная учётная запись,
 * создаётся администратором intentionally).
 *
 * Компания суперадмина (отступление от ADR-005 «company_id = null»): почти все
 * сервисы tenant-изолированы и требуют компанию актора (InputValue::companyId
 * → 409 «Actor has no company»), поэтому platform_admin без компании не может
 * открыть даже список тендеров. Правило привязки:
 *  1) если platform_admin с компанией уже есть — берём его company_id (все
 *     суперадмины сидят в одной служебной компании);
 *  2) иначе — служебная компания-заказчик «Tender Platform Company»
 *     (зарезервированный ИНН PLATFORM_COMPANY_INN), сразу подтверждённая
 *     (approve через workflow company_verification: pending-компания упирается
 *     в org_pending-ограничение CompanyAccessGuard).
 *
 * Запуск: php bin/console app:create:platform-admin [email] [password]
 */
#[AsCommand(
    name: 'app:create:platform-admin',
    description: 'Create a system platform admin (role platform_admin, service tenant)',
)]
final class CreatePlatformAdminCommand extends Command
{
    /** Название служебной компании суперадминов (создаётся при первом запуске). */
    public const string PLATFORM_COMPANY_NAME = 'Tender Platform Company';

    /**
     * Зарезервированный ИНН служебной компании: companies.inn — уникальный
     * NOT NULL, реального ИНН у площадки в bootstrap'е нет. Значение служит
     * ключом идемпотентности — повторный bootstrap находит компанию по нему,
     * а не плодит дубли.
     */
    public const string PLATFORM_COMPANY_INN = '0000000000';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'state_machine.user_status')]
        private readonly WorkflowInterface $userWorkflow,
        #[Autowire(service: 'state_machine.company_verification')]
        private readonly WorkflowInterface $companyWorkflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Email of the platform admin')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password (hidden prompt if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $this->readEmail($input, $output);
        $password = $this->readPassword($input, $output);

        if (null !== $this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            $io->error(\sprintf('User with email "%s" already exists', $email));

            return Command::FAILURE;
        }

        $company = $this->resolvePlatformCompany();

        $user = new User(
            email: $email,
            name: 'Platform Admin',
            role: UserRoleEnum::PLATFORM_ADMIN,
            companyId: $company->getId(),
            locale: LocaleEnum::RU,
        );
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        // Системный аккаунт установки: сразу ACTIVE (email_pending → active по
        // workflow user_status), без email-подтверждения.
        if ($this->userWorkflow->can($user, UserStatusTransition::VERIFY_EMAIL->value)) {
            $this->userWorkflow->apply($user, UserStatusTransition::VERIFY_EMAIL->value);
        }
        $user->markEmailVerified();

        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf(
            'Platform admin created: %s (id=%s, role=%s, status=%s, company=%s "%s")',
            $email,
            (string) $user->getId(),
            $user->getRole()->value,
            $user->getVerificationStatus()->value,
            (string) $company->getId(),
            $company->getLegalName(),
        ));

        return Command::SUCCESS;
    }

    /**
     * Служебная компания суперадминов: компания уже существующего
     * platform_admin, иначе компания по зарезервированному ИНН, иначе —
     * создаём подтверждённую компанию-заказчика.
     */
    private function resolvePlatformCompany(): Company
    {
        $companyId = $this->existingPlatformAdminCompanyId();
        if (null !== $companyId) {
            $company = $this->em->getRepository(Company::class)->find($companyId);
            if (null !== $company) {
                return $company;
            }
        }

        $company = $this->em->getRepository(Company::class)
            ->findOneBy(['inn' => self::PLATFORM_COMPANY_INN]);
        if (null !== $company) {
            return $company;
        }

        $company = new Company(
            legalName: self::PLATFORM_COMPANY_NAME,
            inn: self::PLATFORM_COMPANY_INN,
            type: CompanyTypeEnum::CUSTOMER,
        );
        // pending-компания заблокирована org_pending-ограничением, а подтвердить
        // её некому (первый суперадмин ещё не создан) — approve сразу.
        if ($this->companyWorkflow->can($company, CompanyStatusTransition::APPROVE->value)) {
            $this->companyWorkflow->apply($company, CompanyStatusTransition::APPROVE->value);
        }
        $company->markVerified();

        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * company_id самого раннего platform_admin с привязкой к компании
     * (все суперадмины делят одну служебную компанию).
     */
    private function existingPlatformAdminCompanyId(): ?Uuid
    {
        /** @var User|null $existing */
        $existing = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.companyId IS NOT NULL')
            ->setParameter('role', UserRoleEnum::PLATFORM_ADMIN->value)
            ->orderBy('u.createdAt', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $existing?->getCompanyId();
    }

    private function readEmail(InputInterface $input, OutputInterface $output): string
    {
        $email = $input->getArgument('email');
        if (null !== $email) {
            return $this->validateEmail($email);
        }

        $question = (new Question('Email: '))
            ->setValidator($this->validateEmail(...));

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        /** @var string $answer */
        $answer = $helper->ask($input, $output, $question);

        return $answer;
    }

    private function readPassword(InputInterface $input, OutputInterface $output): string
    {
        $password = $input->getArgument('password');
        if (null !== $password) {
            return $this->validatePassword($password);
        }

        $question = (new Question('Password: '))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setValidator($this->validatePassword(...));

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        /** @var string $answer */
        $answer = $helper->ask($input, $output, $question);

        return $answer;
    }

    /**
     * @throws \RuntimeException если значение не строка или невалидный email
     */
    private function validateEmail(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('A valid email is required');
        }
        $email = strtolower(trim($value));
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid email is required');
        }

        return $email;
    }

    /**
     * @throws \RuntimeException если значение не строка или короче 8 символов
     */
    private function validatePassword(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('Password must be at least 8 characters long');
        }
        if (\strlen($value) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters long');
        }

        return $value;
    }
}
