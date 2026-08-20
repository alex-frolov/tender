<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\Controller\Platform\PlatformTimezoneGetController;
use App\Platform\Controller\Platform\PlatformTimezoneUpdateController;
use App\Platform\Controller\Platform\RateLimitsController;
use App\Platform\Controller\Platform\UsageController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Настройки платформы и лимиты (FR-1.5.15/1.5.16): GET/PUT /platform/timezone,
 * GET /usage, GET /rate-limits.
 *
 * - GET /platform/timezone: любой аутентифицированный; дефолт из env DOMAIN_TIMEZONE;
 * - PUT /platform/timezone: только platform_admin (право platform.timezone.manage);
 *   невалидный IANA → 422;
 * - GET /usage: admin компании (period=day|month);
 * - GET /rate-limits: любой сотрудник компании (peek-значения лимитов).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class PlatformSettingsTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '44.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
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
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    private static function loginAs(string $email): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private static function adminToken(): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'pf-admin-'.random_int(1000, 999999).'@test.ru',
        ]);

        return self::loginAs((string) $user->getEmail());
    }

    private static function platformAdminToken(): string
    {
        $user = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'companyId' => null,
            'email' => 'pf-super-'.random_int(1000, 999999).'@test.ru',
        ]);

        return self::loginAs((string) $user->getEmail());
    }

    public function testGetTimezoneDefaultsToDomainTimezone(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', PlatformTimezoneGetController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['timezone_default']);
    }

    public function testPutTimezoneRequiresPlatformAdmin(): void
    {
        self::client();
        $admin = self::adminToken();

        $client = self::request('PUT', PlatformTimezoneUpdateController::URL, $admin, [
            'timezone_default' => 'Asia/Yekaterinburg',
        ]);
        self::assertResponseStatusCodeSame(403);

        $super = self::platformAdminToken();
        $client = self::request('PUT', PlatformTimezoneUpdateController::URL, $super, [
            'timezone_default' => 'Asia/Yekaterinburg',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Asia/Yekaterinburg', $body['timezone_default']);

        // Прочитать установленное значение.
        $client = self::request('GET', PlatformTimezoneGetController::URL, $admin);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Asia/Yekaterinburg', $body['timezone_default']);
    }

    public function testPutTimezoneRejectsInvalidIana(): void
    {
        self::client();
        $super = self::platformAdminToken();

        $client = self::request('PUT', PlatformTimezoneUpdateController::URL, $super, [
            'timezone_default' => 'Not/AZone',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUsageReturnsCounters(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', UsageController::URL.'?period=day', $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('requests', $body);
        self::assertArrayHasKey('events', $body);
        self::assertArrayHasKey('webhooks', $body);
    }

    public function testRateLimitsReturnsGlobalAndPerTender(): void
    {
        self::client();
        $token = self::adminToken();

        $client = self::request('GET', RateLimitsController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        /** @var array{limit: int, remaining: int} $global */
        $global = $body['global'];
        self::assertArrayHasKey('limit', $global);
        self::assertArrayHasKey('remaining', $global);
        /** @var array<string, array{limit: int, remaining: int}> $perTender */
        $perTender = $body['per_tender'];
        self::assertArrayHasKey('auction_bids', $perTender);
        self::assertArrayHasKey('tender_reads', $perTender);
    }
}
