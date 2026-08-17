<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Команда bootstrap-установки app:create:platform-admin (ADR-005:
 * системная роль вне тенантов, company_id = null).
 *
 * - создаёт platform_admin со статусом ACTIVE (emailVerifiedAt проставлен,
 *   подтверждение email не требуется — системная учётная запись);
 * - пароль хранится хешем (NativePasswordHasher);
 * - повторный запуск с тем же email — ошибка (пользователь уже существует).
 */
final class CreatePlatformAdminCommandTest extends KernelTestCase
{
    /**
     * @return array{CommandTester, string}
     */
    private static function executeCommand(string $email, ?string $password = null): array
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

        return [$tester, $email];
    }

    public function testCreatesPlatformAdminActiveWithoutTenant(): void
    {
        $email = 'admin-'.bin2hex(random_bytes(4)).'@tender.test';
        $password = 'super-secret-123';

        $result = self::executeCommand($email, $password);
        $tester = $result[0];

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Platform admin created', $tester->getDisplay());
        self::assertStringContainsString($email, $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertSame(UserRoleEnum::PLATFORM_ADMIN, $user->getRole());
        self::assertNull($user->getCompanyId(), 'platform admin живёт вне тенантов');
        self::assertSame(UserStatusEnum::ACTIVE, $user->getVerificationStatus());
        self::assertNotNull($user->getEmailVerifiedAt(), 'системный аккаунт сразу подтверждён');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, $password), 'пароль проверяется хешем');
        self::assertNotSame($password, $user->getPasswordHash());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $email = 'dup-'.bin2hex(random_bytes(4)).'@tender.test';

        $result = self::executeCommand($email, 'super-secret-123');
        $result2 = self::executeCommand($email, 'another-secret-456');
        $tester = $result2[0];

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
