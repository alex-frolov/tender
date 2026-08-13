<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\EmailResendController;
use App\Iam\Controller\Auth\EmailVerifyController;
use App\Iam\Controller\Auth\RegisterController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\EmailVerificationToken;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * FR-1.5.5: подтверждение email — токен, cooldown, верификация.
 *
 * Письма уходят асинхронно через messenger в выделенный канал `emails`
 * (in-memory в тестах, when@test) — SMTP не вызывается (MAILER_DSN=null://null).
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 */
final class EmailVerificationTest extends WebTestCase
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

    private static function uniqueEmail(): string
    {
        return \sprintf('verify-%s@test.ru', bin2hex(random_bytes(4)));
    }

    /**
     * POST с уникального IP (rate limit 3/мин на IP в тестах).
     *
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

    private static function findUser(string $email): ?User
    {
        return self::em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * Регистрация компании + admin (FR-1.5.4) — письмо подтверждения уходит сразу.
     */
    private static function register(string $email, string $locale = 'ru'): void
    {
        $client = self::post(RegisterController::URL, [
            'company_name' => 'ООО Проверка Email',
            'inn' => (string) random_int(1000000000, 9999999999),
            'org_type' => 'both',
            'email' => $email,
            'password' => self::PASSWORD,
            'user_name' => 'Иван Тестов',
            'locale' => $locale,
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Письма, переданные последним запросом в очередь messenger (канал `emails`).
     *
     * Ядро (Kernel) тестов перезапускается между запросами (KernelBrowser::reboot),
     * поэтому логгер хранит события только последнего запроса.
     * При Mailer::send() с настроенной шиной в запросе порождается только
     * queued-событие (письмо уходит в транспорт асинхронно); событие фактической
     * SMTP-отправки появится уже у консьюмера, поэтому считаем queued-события.
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
     * Сырой токен подтверждения из тела последнего письма.
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
            self::fail('Verification token not found in email body');
        }

        return $matches[1];
    }

    /**
     * Регистрация + возврат токена подтверждения из письма.
     *
     * @return array{email: string, token: string}
     */
    private static function registerAndGetToken(): array
    {
        $email = self::uniqueEmail();
        self::register($email);

        return ['email' => $email, 'token' => self::lastToken(self::sentEmails())];
    }

    public function testRegisterSendsVerificationEmailAndUserStaysPending(): void
    {
        self::client();
        $result = self::registerAndGetToken();
        $email = $result['email'];
        $token = $result['token'];

        self::assertSame(64, \strlen($token));

        // пользователь email_pending, подтверждение не зафиксировано
        $user = self::findUser($email);
        self::assertNotNull($user);
        self::assertSame(UserStatusEnum::EMAIL_PENDING, $user->getVerificationStatus());
        self::assertNull($user->getEmailVerifiedAt());

        // в БД — только sha256-хеш токена (безопасность при утечке БД)
        $stored = self::em()->getRepository(EmailVerificationToken::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $token), $stored[0]->getTokenHash());
        self::assertNotSame($token, $stored[0]->getTokenHash());
        self::assertFalse($stored[0]->isUsed());
    }

    public function testVerificationEmailUsesUserLocale(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::register($email, 'en');

        $emails = self::sentEmails();
        self::assertCount(1, $emails);
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);
        self::assertSame('Email confirmation — Tender Platform', $last->getSubject());
        $body = $last->getTextBody();
        self::assertIsString($body);
        self::assertStringContainsString('To activate your account', $body);
        self::assertMatchesRegularExpression('/token=([a-f0-9]{64})/', $body);
    }

    public function testVerificationEmailGoesToDedicatedMessengerChannel(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::register($email);

        // письмо уходит в выделенный транспорт `emails` как SendEmailMessage
        $transport = static::getContainer()->get('messenger.transport.emails');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $envelopes = array_values(iterator_to_array($transport->get()));
        self::assertCount(1, $envelopes, 'в канал почты должно попасть ровно одно письмо');
        $queued = $envelopes[0]->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $queued);
        $mail = $queued->getMessage();
        self::assertInstanceOf(Email::class, $mail);
        $to = array_map(static fn (Address $a) => $a->getAddress(), $mail->getTo());
        self::assertSame([$email], $to);

        // в самом запросе письмо синхронно не отправляется (только queued-события)
        $logger = static::getContainer()->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);
        foreach ($logger->getEvents()->getEvents() as $event) {
            self::assertTrue($event->isQueued(), 'письмо не должно отправляться напрямую в запросе');
        }
    }

    public function testVerifyTokenActivatesUser(): void
    {
        self::client();
        $r = self::registerAndGetToken();
        $email = $r['email'];
        $token = $r['token'];

        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['email_verified'] ?? false);

        // статус ACTIVE, токен использован
        $user = self::findUser($email);
        self::assertNotNull($user);
        self::assertSame(UserStatusEnum::ACTIVE, $user->getVerificationStatus());
        self::assertNotNull($user->getEmailVerifiedAt());

        $tokenEntities = self::em()->getRepository(EmailVerificationToken::class)->findAll();
        self::assertCount(1, $tokenEntities);
        self::assertTrue($tokenEntities[0]->isUsed());
    }

    public function testVerifyWithInvalidTokenReturns401(): void
    {
        self::client();
        self::register(self::uniqueEmail());

        $client = self::post(EmailVerifyController::URL, ['token' => str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testVerifyWithExpiredTokenReturns401(): void
    {
        self::client();
        $r = self::registerAndGetToken();
        $email = $r['email'];
        $token = $r['token'];

        // протухаем токен напрямую в БД
        self::em()->createQueryBuilder()
            ->update(EmailVerificationToken::class, 't')
            ->set('t.expiresAt', ':past')
            ->where('t.tokenHash = :hash')
            ->setParameter('past', new \DateTimeImmutable('-1 hour'))
            ->setParameter('hash', hash('sha256', $token))
            ->getQuery()
            ->execute();

        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(401);

        // пользователь остался email_pending
        $user = self::findUser($email);
        self::assertNotNull($user);
        self::assertSame(UserStatusEnum::EMAIL_PENDING, $user->getVerificationStatus());
    }

    public function testVerifyTokenIsSingleUse(): void
    {
        self::client();
        $r = self::registerAndGetToken();
        $token = $r['token'];

        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(200);

        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testResendIssuesNewTokenAndInvalidatesOld(): void
    {
        self::client();
        $r = self::registerAndGetToken();
        $email = $r['email'];
        $firstToken = $r['token'];

        $client = self::post(EmailResendController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('sent', $body['status'] ?? null);

        // письмо ушло (логгер — только последний запрос); в БД два токена
        $emails = self::sentEmails();
        self::assertCount(1, $emails);
        $secondToken = self::lastToken($emails);
        self::assertNotSame($firstToken, $secondToken);

        $stored = self::em()->getRepository(EmailVerificationToken::class)->findAll();
        self::assertCount(2, $stored, 'resend выпустил второй токен');
        $used = array_filter($stored, static fn (EmailVerificationToken $t) => $t->isUsed());
        self::assertCount(1, $used, 'предыдущий токен инвалидирован при resend');

        // старый токен инвалидирован, новый работает
        $client = self::post(EmailVerifyController::URL, ['token' => $firstToken]);
        self::assertResponseStatusCodeSame(401);

        $client = self::post(EmailVerifyController::URL, ['token' => $secondToken]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testResendWithUnknownEmailReturns202WithoutSending(): void
    {
        self::client();

        $client = self::post(EmailResendController::URL, ['email' => 'nobody@test.ru']);
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('not_found', $body['status'] ?? null);

        self::assertCount(0, self::sentEmails(), 'письмо не уходит для неизвестного email (не раскрываем наличие)');
    }

    public function testResendForVerifiedUserDoesNotSend(): void
    {
        self::client();
        $r = self::registerAndGetToken();
        $email = $r['email'];
        $token = $r['token'];

        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(200);

        $client = self::post(EmailResendController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('already_verified', $body['status'] ?? null);

        // после verify+resend новых писем нет (логгер показывает события последнего запроса)
        self::assertCount(0, self::sentEmails(), 'подтверждённому пользователю письмо не отправляется');
    }

    public function testResendCooldownReturns429(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::register($email);

        // email_send: fixed_window 5 / 10 минут — первые 5 resend проходят
        for ($i = 0; $i < 5; ++$i) {
            $client = self::post(EmailResendController::URL, ['email' => $email]);
            self::assertResponseStatusCodeSame(202);
        }

        // 6-й — 429 с Retry-After (cooldown, RL-1)
        $client = self::post(EmailResendController::URL, ['email' => $email]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Too Many Requests', $body['title'] ?? null);
    }

    public function testUnverifiedUserCannotLogin(): void
    {
        self::client();
        $email = self::uniqueEmail();
        self::register($email);

        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }
}
