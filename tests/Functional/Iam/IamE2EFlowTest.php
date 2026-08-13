<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\EmailVerifyController;
use App\Iam\Controller\Auth\RegisterController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\RefreshToken;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;

/**
 * E2E IAM: сквозной сценарий
 * регистрация → подтверждение email → вход (токен).
 *
 * Покрывает FR-1.5.4 (регистрация), FR-1.5.5 (подтверждение email),
 * FR-1.5.3 (вход по email+пароль). Компания остаётся pending до
 * подтверждения суперадмином (FR-1.5.7) — это отдельный сценарий
 * (CompanyModerationTest); здесь проверяется жизненный путь пользователя.
 *
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 * Письма уходят асинхронно в канал `emails` (in-memory в тестах, when@test).
 */
final class IamE2EFlowTest extends WebTestCase
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
        return \sprintf('e2e-%s@test.ru', bin2hex(random_bytes(4)));
    }

    /**
     * @param array<mixed> $data
     */
    private static function post(string $url, array $data): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], self::json($data));

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

    /**
     * Письма, переданные последним запросом в очередь messenger (канал `emails`).
     * Ядро перезапускается между запросами, поэтому логгер хранит события
     * только последнего запроса; считаем queued-события.
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
     * Сырой токен подтверждения из тела письма.
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
     * Шаг 1: регистрация компании + первый admin (FR-1.5.4).
     *
     * @return array{company_id: string, user_id: string, email: string}
     */
    private static function register(string $email): array
    {
        $client = self::post(RegisterController::URL, [
            'company_name' => 'ООО E2E Поток',
            'inn' => (string) random_int(1000000000, 9999999999),
            'org_type' => 'both',
            'email' => $email,
            'password' => self::PASSWORD,
            'user_name' => 'Иван E2E',
        ]);
        self::assertResponseStatusCodeSame(201);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('pending', $body['verification_status']);

        $companyId = $body['company_id'] ?? null;
        $userId = $body['user_id'] ?? null;
        self::assertIsString($companyId);
        self::assertIsString($userId);

        return [
            'company_id' => $companyId,
            'user_id' => $userId,
            'email' => $email,
        ];
    }

    /**
     * Шаг 2: подтверждение email по токену из письма (FR-1.5.5).
     */
    private static function verifyEmail(string $token): void
    {
        $client = self::post(EmailVerifyController::URL, ['token' => $token]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['email_verified'] ?? false);
    }

    /**
     * Шаг 3: вход по email+пароль (FR-1.5.3).
     *
     * @return array{access_token: string, refresh_token: string, token_type: string}
     */
    private static function login(string $email): array
    {
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('access_token', $body);
        self::assertArrayHasKey('refresh_token', $body);

        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;
        $tokenType = $body['token_type'] ?? null;
        self::assertIsString($access);
        self::assertIsString($refresh);
        self::assertIsString($tokenType);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => $tokenType,
        ];
    }

    public function testFullRegistrationConfirmLoginFlow(): void
    {
        self::client();
        $email = self::uniqueEmail();

        // 1. регистрация → компания pending + admin email_pending
        $reg = self::register($email);
        $user = self::em()->getRepository(User::class)->find($reg['user_id']);
        self::assertNotNull($user);
        self::assertSame(UserRoleEnum::ADMIN, $user->getRole());
        self::assertSame(UserStatusEnum::EMAIL_PENDING, $user->getVerificationStatus());

        $company = self::em()->getRepository(Company::class)->find($reg['company_id']);
        self::assertNotNull($company);
        self::assertSame('pending', $company->getVerificationStatus()->value);

        // 2. письмо подтверждения ушло; извлекаем токен
        $emails = self::sentEmails();
        self::assertCount(1, $emails);
        $token = self::lastToken($emails);

        // 3. подтверждение email → пользователь active
        self::verifyEmail($token);
        $user = self::em()->getRepository(User::class)->find($reg['user_id']);
        self::assertNotNull($user);
        self::assertSame(UserStatusEnum::ACTIVE, $user->getVerificationStatus());
        self::assertNotNull($user->getEmailVerifiedAt());

        // 4. вход → access+refresh
        $tokens = self::login($email);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertNotEmpty($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);

        // refresh сохранён в БД (хеш)
        $refresh = self::em()->getRepository(RefreshToken::class)->findAll();
        self::assertCount(1, $refresh);
        self::assertSame(hash('sha256', $tokens['refresh_token']), $refresh[0]->getTokenHash());
    }

    public function testLoginBeforeEmailConfirmationIsRejected(): void
    {
        self::client();
        $email = self::uniqueEmail();

        self::register($email);

        // вход до подтверждения email → 401 (email_pending)
        $client = self::post(TokenController::URL, ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }
}
