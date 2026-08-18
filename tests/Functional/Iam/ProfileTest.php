<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\User\MeController;
use App\Iam\Controller\User\UpdateMeController;
use App\Tests\Story\VerifiedUserStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.8: профиль текущего пользователя (GET/PATCH /users/me).
 * - GET: возвращает пользователя и его компанию;
 * - PATCH: смена имени; смена пароля только с верным current_password (422);
 *   при смене пароля revoke refresh-токенов (старый refresh не работает).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ProfileTest extends WebTestCase
{
    private const EMAIL = VerifiedUserStory::EMAIL;
    private const PASSWORD = VerifiedUserStory::PASSWORD;

    /** @var KernelBrowser|null один клиент на тест */
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
        return '12.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function login(): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['email' => self::EMAIL, 'password' => self::PASSWORD]),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @param array<mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $data ? '' : self::json($data),
        );

        return $client;
    }

    public function testGetMeReturnsUserAndCompany(): void
    {
        self::client();
        VerifiedUserStory::load();
        $token = self::login();

        $client = self::request('GET', MeController::URL, $token);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        /** @var array{user: array<string, mixed>, company: array<string, mixed>|null} $body */
        self::assertSame(self::EMAIL, $body['user']['email']);
        self::assertIsArray($body['company']);
        self::assertSame('ООО Аутентификация', $body['company']['legal_name']);
    }

    public function testGetMeRequiresAuth(): void
    {
        self::client();
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('GET', MeController::URL);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUpdateMeChangesName(): void
    {
        self::client();
        VerifiedUserStory::load();
        $token = self::login();

        $client = self::request('PATCH', UpdateMeController::URL, $token, ['name' => 'Новое Имя']);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Новое Имя', $body['name']);

        // повторный GET — имя сохранено
        $client = self::request('GET', MeController::URL, $token);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        /** @var array{user: array<string, mixed>} $body */
        self::assertSame('Новое Имя', $body['user']['name']);
    }

    public function testUpdateMePasswordRequiresCurrentPassword(): void
    {
        self::client();
        VerifiedUserStory::load();
        $token = self::login();

        // без current_password → 422
        $client = self::request('PATCH', UpdateMeController::URL, $token, ['new_password' => 'newsecret123']);
        self::assertResponseStatusCodeSame(422);

        // неверный current_password → 422
        $client = self::request('PATCH', UpdateMeController::URL, $token, [
            'current_password' => 'wrong-password',
            'new_password' => 'newsecret123',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateMePasswordRevokesRefreshTokens(): void
    {
        self::client();
        VerifiedUserStory::load();
        $token = self::login();
        $refreshToken = $this->refreshTokenFromLogin();

        // смена пароля с верным current_password → 200
        $client = self::request('PATCH', UpdateMeController::URL, $token, [
            'current_password' => self::PASSWORD,
            'new_password' => 'newsecret123',
        ]);
        self::assertResponseStatusCodeSame(200);

        // старый refresh-токен больше не работает → 401
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            '/api/v1/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['refresh_token' => $refreshToken]),
        );
        self::assertResponseStatusCodeSame(401);
    }

    private function refreshTokenFromLogin(): string
    {
        // refresh-токен из свежего логина (уже есть access, но refresh нужен отдельно)
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['email' => self::EMAIL, 'password' => self::PASSWORD]),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['refresh_token']);

        return $body['refresh_token'];
    }
}
