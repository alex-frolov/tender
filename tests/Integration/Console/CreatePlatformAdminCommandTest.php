<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use App\Infrastructure\Console\CreatePlatformAdminCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Команда bootstrap-установки app:create:platform-admin.
 *
 * - создаёт platform_admin со статусом ACTIVE (emailVerifiedAt проставлен,
 *   подтверждение email не требуется — системная учётная запись);
 * - привязывает его к служебной компании «Tender Platform Company»
 *   (подтверждённая компания-заказчик) — без компании суперадмин упирается
 *   в 409 «Actor has no company» на любом tenant-изолированном endpoint;
 * - второй суперадмин попадает в ту же компанию, что и первый;
 * - пароль хранится хешем (NativePasswordHasher);
 * - повторный запуск с тем же email — ошибка (пользователь уже существует).
 */
final class CreatePlatformAdminCommandTest extends KernelTestCase
{
    private static function executeCommand(string $email, ?string $password = null): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:create:platform-admin');
        $tester = new CommandTester($command);

        $argv = ['command' => 'app:create:platform-admin', 'email' => $email];
        if (null !== $password) {
            $argv['password'] = $password;
        }
        $tester->execute($argv, ['interactive' => false]);

        return $tester;
    }

    public function testCreatesPlatformAdminActiveWithServiceCompany(): void
    {
        $email = 'admin-'.bin2hex(random_bytes(4)).'@tender.test';
        $password = 'super-secret-123';

        $tester = self::executeCommand($email, $password);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Platform admin created', $tester->getDisplay());
        self::assertStringContainsString($email, $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertSame(UserRoleEnum::PLATFORM_ADMIN, $user->getRole());
        self::assertSame(UserStatusEnum::ACTIVE, $user->getVerificationStatus());
        self::assertNotNull($user->getEmailVerifiedAt(), 'системный аккаунт сразу подтверждён');

        $companyId = $user->getCompanyId();
        self::assertNotNull($companyId, 'суперадмин привязан к служебной компании');

        $company = $em->getRepository(Company::class)->find($companyId);
        self::assertNotNull($company);
        self::assertSame(CreatePlatformAdminCommand::PLATFORM_COMPANY_NAME, $company->getLegalName());
        self::assertSame(CreatePlatformAdminCommand::PLATFORM_COMPANY_INN, $company->getInn());
        self::assertSame(CompanyTypeEnum::CUSTOMER, $company->getType());
        self::assertSame(
            CompanyStatusEnum::ACTIVE,
            $company->getVerificationStatus(),
            'компания подтверждена — иначе org_pending блокирует доступ',
        );
        self::assertNotNull($company->getVerifiedAt());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, $password), 'пароль проверяется хешем');
        self::assertNotSame($password, $user->getPasswordHash());
    }

    public function testSecondPlatformAdminReusesTheSameCompany(): void
    {
        $first = 'admin-'.bin2hex(random_bytes(4)).'@tender.test';
        $second = 'admin-'.bin2hex(random_bytes(4)).'@tender.test';

        self::executeCommand($first, 'super-secret-123');
        $tester = self::executeCommand($second, 'super-secret-456');
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $firstUser = $em->getRepository(User::class)->findOneBy(['email' => $first]);
        $secondUser = $em->getRepository(User::class)->findOneBy(['email' => $second]);
        self::assertNotNull($firstUser);
        self::assertNotNull($secondUser);
        self::assertNotNull($firstUser->getCompanyId());
        self::assertTrue(
            $firstUser->getCompanyId()->equals($secondUser->getCompanyId()),
            'все суперадмины сидят в одной служебной компании',
        );
    }

    public function testRejectsDuplicateEmail(): void
    {
        $email = 'dup-'.bin2hex(random_bytes(4)).'@tender.test';

        self::executeCommand($email, 'super-secret-123');
        $tester = self::executeCommand($email, 'another-secret-456');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
