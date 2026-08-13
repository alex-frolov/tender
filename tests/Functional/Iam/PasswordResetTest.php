<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\PasswordForgotController;
use App\Iam\Controller\Auth\PasswordResetController;
use App\Iam\Controller\Auth\RefreshController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\PasswordResetToken;
use App\Iam\Entity\RefreshToken;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;

/**
 * FR-1.5.6: восстановление пароля — одноразовый токен, TTL, сброс.
 *
 * Письма уходят асинхронно через messenger в канал `emails` (in-memory в тестах).
 * Rate limit email_send (Redis, 5/10 мин) — общий на email и не сбрасывается между
 * тестами, поэтому каждый тест использует УНИКАЛЬНЫЙ email (как EmailVerificationTest).
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 */
final class PasswordResetTest extends WebTestCase
{
    private const PASSWORD = 'secret123';

    /** @var KernelBrowser|null один клиент на тест (createClient() можно вызвать только один раз) */
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= static::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<mixed> $data
     */
    private static function post(string $path, array $data): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], self::json($data));

        return $client;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            throw new \LogicException('Cannot encode JSON');
        }

        return $json;
    }

    private static function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private static function uniqueEmail(): string
    {
        return \sprintf('reset-%s@test.ru', bin2hex(random_bytes(4)));
    }

    /**
     * Письма, переданные последним запросом в очередь messenger (канал `emails`).
     *
     * @return list<Email>
     */
    private static function sentEmails(): array
    {
        $logger = static::getContainer()->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);

        $emails = [];
        foreach ($logger->getEvents()->getEvents() as $event) {
            if (!$event->isQueued()) {
                continue;
            }
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    /**
     * Сырой токен сброса из тела последнего письма.
     *
     * @param list<Email> $emails
     */
    private static function lastToken(array $emails): string
    {
        self::assertNotEmpty($emails);
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);
        $body = $last->getTextBody();
        self::assertIsString($body);

        $matches = [];
        if (1 !== preg_match('/token=([a-f0-9]{64})/', $body, $matches)) {
            self::fail('Reset token not found in email body');
        }

        return $matches[1];
    }

    /**
     * Подтверждённый пользователь с уникальным email (FR-1.5.6).
     * Возвращает email — остальные тесты работают с ним.
     */
    private static function createUser(string $email, LocaleEnum $locale = LocaleEnum::RU): void
    {
        UserFactory::createOne([
            'email' => $email,
            'password' => self::PASSWORD,
            'locale' => $locale,
        ]);
    }

    /**
     * Пользователь + запрос восстановления + возврат токена.
     */
    private static function forgotAndGetToken(string $email): string
    {
        $client = self::post(PasswordForgotController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(202);

        return self::lastToken(self::sentEmails());
    }

    public function testForgotSendsResetEmailAndStoresHashOnly(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);
        $token = self::forgotAndGetToken($email);

        self::assertSame(64, \strlen($token));

        // в БД — только sha256-хеш токена (безопасность при утечке БД)
        $stored = self::em()->getRepository(PasswordResetToken::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $token), $stored[0]->getTokenHash());
        self::assertNotSame($token, $stored[0]->getTokenHash());
        self::assertFalse($stored[0]->isUsed());
    }

    public function testForgotWithUnknownEmailReturnsAcceptedWithoutSending(): void
    {
        self::client();

        $client = self::post(PasswordForgotController::URL, ['email' => 'nobody@test.ru']);
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('not_found', $body['status'] ?? null);

        self::assertCount(0, self::sentEmails(), 'письмо не уходит для неизвестного email (не раскрываем наличие)');
    }

    public function testForgotCooldownReturns429(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);

        // email_send: fixed_window 5 / 10 минут — первые 5 запросов проходят
        for ($i = 0; $i < 5; ++$i) {
            $client = self::post(PasswordForgotController::URL, ['email' => $email]);
            self::assertResponseStatusCodeSame(202);
        }

        // 6-й — 429 с Retry-After (cooldown, RL-1)
        $client = self::post(PasswordForgotController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Too Many Requests', $body['title'] ?? null);
    }

    public function testForgotEmailUsesUserLocale(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email, LocaleEnum::EN);

        $client = self::post(PasswordForgotController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(202);

        $emails = self::sentEmails();
        self::assertCount(1, $emails);
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);
        self::assertSame('Password reset — Tender Platform', $last->getSubject());
        $body = $last->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('To reset your password', $body);
        self::assertMatchesRegularExpression('/token=([a-f0-9]{64})/', $body);
    }

    public function testResetChangesPasswordAndRevokesSessions(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);
        $token = self::forgotAndGetToken($email);

        // перед сбросом залогинимся — выдаст refresh-токен
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
        $login = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($login);
        self::assertIsString($login['refresh_token'] ?? null);

        // сброс пароля
        $newPassword = 'new-secret-456';
        $client = self::post(PasswordResetController::URL, ['token' => $token, 'new_password' => $newPassword]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['password_reset'] ?? false);

        // старый пароль больше не работает, новый — работает
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => $newPassword]);
        self::assertResponseStatusCodeSame(200);

        // refresh-токены пользователя отозваны (сессии инвалидированы)
        $client = self::post(RefreshController::URL, ['refresh_token' => $login['refresh_token']]);
        self::assertResponseStatusCodeSame(401);

        // токен сброса использован (одноразовый)
        $entity = self::em()->getRepository(PasswordResetToken::class)->findAll()[0];
        self::assertTrue($entity->isUsed());
    }

    public function testResetWithInvalidTokenReturns401(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);

        $client = self::post(PasswordResetController::URL, ['token' => str_repeat('0', 64), 'new_password' => 'new-secret-456']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testResetWithExpiredTokenReturns401(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);
        $token = self::forgotAndGetToken($email);

        // протухаем токен напрямую в БД
        self::em()->createQueryBuilder()
            ->update(PasswordResetToken::class, 't')
            ->set('t.expiresAt', ':past')
            ->where('t.tokenHash = :hash')
            ->setParameter('past', new \DateTimeImmutable('-1 hour'))
            ->setParameter('hash', hash('sha256', $token))
            ->getQuery()
            ->execute();

        $client = self::post(PasswordResetController::URL, ['token' => $token, 'new_password' => 'new-secret-456']);
        self::assertResponseStatusCodeSame(401);

        // пароль не изменился
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testResetTokenIsSingleUse(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);
        $token = self::forgotAndGetToken($email);

        $client = self::post(PasswordResetController::URL, ['token' => $token, 'new_password' => 'new-secret-456']);
        self::assertResponseStatusCodeSame(200);

        $client = self::post(PasswordResetController::URL, ['token' => $token, 'new_password' => 'another-secret-789']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testResetRevokesAllRefreshTokens(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::createUser($email);
        $token = self::forgotAndGetToken($email);

        // два refresh-токена для пользователя (два логина)
        for ($i = 0; $i < 2; ++$i) {
            $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
            self::assertResponseStatusCodeSame(200);
        }

        $client = self::post(PasswordResetController::URL, ['token' => $token, 'new_password' => 'new-secret-456']);
        self::assertResponseStatusCodeSame(200);

        $tokens = self::em()->getRepository(RefreshToken::class)->findAll();
        self::assertNotEmpty($tokens);
        foreach ($tokens as $rt) {
            self::assertTrue($rt->isRevoked(), 'после сброса пароля все refresh-токены отозваны');
        }
    }
}
