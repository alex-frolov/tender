<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\LogoutController;
use App\Iam\Controller\Auth\RefreshController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Auth\TwoFactorConfirmController;
use App\Iam\Controller\Auth\TwoFactorDisableController;
use App\Iam\Controller\Auth\TwoFactorSetupController;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\RefreshToken;
use App\Iam\Entity\User;
use App\Shared\Totp\TotpService;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.3: аутентификация (token/refresh/logout) + 2FA TOTP.
 *
 * Rate limit в тестах = 3/мин на IP (config/packages/test/rate_limiter.yaml),
 * поэтому КАЖДЫЙ запрос идёт с уникального IP (новый клиент на запрос).
 */
final class AuthenticationTest extends WebTestCase
{
    private const EMAIL = VerifiedUserStory::EMAIL;
    private const PASSWORD = VerifiedUserStory::PASSWORD;

    /** @var KernelBrowser|null один клиент на тест (createClient() можно вызвать только один раз) */
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    /**
     * Один клиент на тест: createClient() можно вызвать только один раз
     * (Symfony 8.1: повторное создание после boot запрещено).
     */
    private static function client(): KernelBrowser
    {
        self::$client ??= static::createClient();

        return self::$client;
    }

    /**
     * Уникальный IP для запроса (rate limit в тестах = 3/мин на IP).
     */
    private static function uniqueIp(): string
    {
        return '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * Подтверждённый пользователь через Story (компания + роль задаются Story).
     */
    private static function createVerifiedUser(?UserStatusEnum $status = null): User
    {
        $user = VerifiedUserStory::user();
        if (null !== $status) {
            $user->setVerificationStatus($status);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        return $user;
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

    /**
     * POST на URL контроллера с нового IP на общем клиенте.
     *
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
     * POST на URL контроллера с Bearer-токеном.
     *
     * @param array<mixed> $data
     */
    private static function authedPost(string $url, string $accessToken, array $data): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken],
            self::json($data),
        );

        return $client;
    }

    /**
     * @return array<mixed>
     */
    private static function login(): array
    {
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('access_token', $body);
        self::assertArrayHasKey('refresh_token', $body);

        return $body;
    }

    public function testTokenReturnsAccessAndRefresh(): void
    {
        self::client(); // boot kernel
        self::createVerifiedUser();

        $tokens = self::login();

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(900, $tokens['expires_in']);
        self::assertNotEmpty($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);

        // refresh сохранён в БД (хеш)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refresh = $em->getRepository(RefreshToken::class)->findAll();
        self::assertCount(1, $refresh);
        self::assertNotSame($tokens['refresh_token'], $refresh[0]->getTokenHash());
        self::assertSame(hash('sha256', $tokens['refresh_token']), $refresh[0]->getTokenHash());
    }

    public function testTokenWithWrongPasswordReturns401(): void
    {
        self::client();
        self::createVerifiedUser();

        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenWithUnknownEmailReturns401(): void
    {
        self::client();
        self::createVerifiedUser();

        $client = self::post(TokenController::URL, ['email' => 'nobody@test.ru', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshRotatesToken(): void
    {
        self::client();
        self::createVerifiedUser();

        $first = self::login();

        // refresh → новая пара, старый отозван
        $client = self::post(RefreshController::URL, ['refresh_token' => $first['refresh_token']]);
        self::assertResponseStatusCodeSame(200);
        $second = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($second);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotEmpty($second['access_token']);

        // старый refresh отозван → 401
        $client = self::post(RefreshController::URL, ['refresh_token' => $first['refresh_token']]);
        self::assertResponseStatusCodeSame(401);

        // в БД два refresh: старый revoked, новый активный
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = $em->getRepository(RefreshToken::class)->findAll();
        self::assertCount(2, $tokens);
        $revoked = array_filter($tokens, static fn (RefreshToken $t) => $t->isRevoked());
        self::assertCount(1, $revoked);
    }

    public function testLogoutRevokesRefreshToken(): void
    {
        self::client();
        self::createVerifiedUser();

        $tokens = self::login();

        $client = self::post(LogoutController::URL, ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(200);

        // повторный refresh → 401
        $client = self::post(RefreshController::URL, ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(401);

        // повторный logout — идемпотентен (200)
        $client = self::post(LogoutController::URL, ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTwoFactorLoginFlow(): void
    {
        self::client();
        $user = self::createVerifiedUser();

        // логин без 2FA — работает
        self::login();

        // включаем 2FA
        $totp = static::getContainer()->get(TotpService::class);
        $secret = 'JBSWY3DPEHPK3PXP';
        $user->enableTwoFactor($secret);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // без кода → 401
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);

        // с неверным кодом → 401
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD, 'totp_code' => '000000']);
        self::assertResponseStatusCodeSame(401);

        // с верным кодом → 200
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD, 'totp_code' => $totp->generate($secret)]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('access_token', $body);
    }

    public function testTwoFactorApiSetupConfirmDisableFlow(): void
    {
        self::client();
        self::createVerifiedUser();

        $tokens = self::login();
        self::assertIsString($tokens['access_token']);
        $access = $tokens['access_token'];

        // без токена 2FA-эндпоинты недоступны
        $client = self::post(TwoFactorSetupController::URL, []);
        self::assertResponseStatusCodeSame(401);

        // setup → секрет + otpauth-URI
        $client = self::authedPost(TwoFactorSetupController::URL, $access, []);
        self::assertResponseStatusCodeSame(200);
        $setup = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($setup);
        self::assertArrayHasKey('secret', $setup);
        self::assertArrayHasKey('otpauth_uri', $setup);
        self::assertIsString($setup['secret']);
        self::assertIsString($setup['otpauth_uri']);
        $secret = $setup['secret'];
        self::assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);

        // неверный код → 422, 2FA не включена
        $client = self::authedPost(TwoFactorConfirmController::URL, $access, ['secret' => $secret, 'code' => '000000']);
        self::assertResponseStatusCodeSame(422);

        // верный код → включена
        $totp = static::getContainer()->get(TotpService::class);
        $client = self::authedPost(TwoFactorConfirmController::URL, $access, ['secret' => $secret, 'code' => $totp->generate($secret)]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['two_factor_enabled']);

        // повторный setup → 409
        $client = self::authedPost(TwoFactorSetupController::URL, $access, []);
        self::assertResponseStatusCodeSame(409);

        // логин теперь требует код
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD, 'totp_code' => $totp->generate($secret)]);
        self::assertResponseStatusCodeSame(200);

        // disable: неверный код → 422
        $client = self::authedPost(TwoFactorDisableController::URL, $access, ['code' => '000000']);
        self::assertResponseStatusCodeSame(422);

        // disable: верный код → отключено
        $client = self::authedPost(TwoFactorDisableController::URL, $access, ['code' => $totp->generate($secret)]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertFalse($body['two_factor_enabled']);

        // логин снова без кода
        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testBlockedUserCannotLogin(): void
    {
        self::client();
        self::createVerifiedUser(UserStatusEnum::BLOCKED);

        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testEmailPendingUserCannotLogin(): void
    {
        self::client();
        $user = self::createVerifiedUser();
        $user->setVerificationStatus(UserStatusEnum::EMAIL_PENDING);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvitedUserCannotLogin(): void
    {
        self::client();
        $user = self::createVerifiedUser();
        $user->setVerificationStatus(UserStatusEnum::INVITED);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client = self::post(TokenController::URL, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }
}
