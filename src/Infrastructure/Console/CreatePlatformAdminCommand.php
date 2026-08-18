<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

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
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Создание системного суперадминистратора (роль platform_admin, ADR-005:
 * вне тенантов, company_id = null).
 *
 * Предназначена для bootstrap'а установки (первичный доступ) — не для
 * повседневного управления пользователями (это делает UserManagementService
 * в рамках компании). Повторный запуск с тем же email — ошибка (пользователь
 * уже существует). Созданный пользователь сразу ACTIVE (emailVerifiedAt
 * проставлен) — подтверждение email не требуется (системная учётная запись,
 * создаётся администратором intentionally).
 *
 * Запуск: php bin/console app:create:platform-admin [email] [password]
 */
#[AsCommand(
    name: 'app:create:platform-admin',
    description: 'Create a system platform admin (role platform_admin, outside tenants)',
)]
final class CreatePlatformAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'state_machine.user_status')]
        private readonly WorkflowInterface $userWorkflow,
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

        $user = new User(
            email: $email,
            name: 'Platform Admin',
            role: UserRoleEnum::PLATFORM_ADMIN,
            companyId: null,
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
            'Platform admin created: %s (id=%s, role=%s, status=%s)',
            $email,
            (string) $user->getId(),
            $user->getRole()->value,
            $user->getVerificationStatus()->value,
        ));

        return Command::SUCCESS;
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
