<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Company\CompanyListController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.7: реестр компаний площадки (GET /admin/companies) — рабочий экран
 * модерации суперадмина.
 * - список видит только platform_admin (обычный admin компании → 403);
 * - фильтр ?status= отдаёт очередь на верификацию (pending);
 * - фильтр ?q= ищет по названию и ИНН;
 * - ?limit=/?cursor= — keyset-пагинация (next_cursor).
 */
final class CompanyRegistryTest extends WebTestCase
{
    private const PLATFORM_EMAIL = 'registry-sa@test.ru';
    private const PASSWORD = 'secret123';

    /** @var KernelBrowser|null один клиент на тест */
    private static ?KernelBrowser $client = null;

    /** @var list<string> статусы строк последней прочитанной страницы */
    private static array $statuses = [];

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
    private static function json(array $data): string
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            throw new \LogicException('Cannot encode JSON');
        }

        return $json;
    }

    private static function login(string $email = self::PLATFORM_EMAIL): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['email' => $email, 'password' => self::PASSWORD]),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * Страница реестра. Из items берётся только то, что нужно проверкам:
     * id компаний по порядку (см. statuses() для статусов строк).
     *
     * @param array<string, string|int> $query
     *
     * @return array{items: list<string>, next_cursor: string|null} items — id компаний страницы
     */
    private static function list(string $token, array $query = []): array
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $url = CompanyListController::URL.([] === $query ? '' : '?'.http_build_query($query));
        $client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        if (!isset($body['items'])) {
            return ['items' => [], 'next_cursor' => null];
        }

        self::assertIsArray($body['items']);
        $items = [];
        $statuses = [];
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['id']);
            self::assertIsString($item['verification_status']);
            $items[] = $item['id'];
            $statuses[] = $item['verification_status'];
        }
        self::$statuses = $statuses;

        $nextCursor = $body['next_cursor'] ?? null;
        self::assertTrue(null === $nextCursor || \is_string($nextCursor));

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    public function testPlatformAdminSeesEveryCompany(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
        $pending = CompanyFactory::createOne(['legalName' => 'ООО Ждущая']);
        $active = CompanyFactory::new()->approved()->create(['legalName' => 'ООО Активная']);

        $page = self::list(self::login());
        self::assertResponseStatusCodeSame(200);

        $ids = $page['items'];
        self::assertContains((string) $pending->getId(), $ids);
        self::assertContains((string) $active->getId(), $ids);
    }

    public function testStatusFilterReturnsVerificationQueue(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
        $pending = CompanyFactory::createOne();
        $active = CompanyFactory::new()->approved()->create();

        $page = self::list(self::login(), ['status' => 'pending', 'limit' => 100]);
        self::assertResponseStatusCodeSame(200);

        $ids = $page['items'];
        self::assertContains((string) $pending->getId(), $ids);
        self::assertNotContains((string) $active->getId(), $ids);
        self::assertSame(array_fill(0, \count($ids), 'pending'), self::$statuses);
    }

    public function testSearchMatchesLegalNameAndInn(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
        $target = CompanyFactory::createOne(['legalName' => 'АО Уникальное Имя', 'inn' => '7712345678']);
        CompanyFactory::createOne(['legalName' => 'ООО Другая', 'inn' => '7787654321']);

        $token = self::login();

        $byName = self::list($token, ['q' => 'уникальное', 'limit' => 100]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $target->getId()], $byName['items']);

        $byInn = self::list($token, ['q' => '7712345678', 'limit' => 100]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $target->getId()], $byInn['items']);
    }

    public function testCursorPaginatesWithoutRepeats(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
        CompanyFactory::createMany(3);

        $token = self::login();
        $first = self::list($token, ['limit' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $first['items']);
        self::assertIsString($first['next_cursor']);

        $second = self::list($token, ['limit' => 2, 'cursor' => $first['next_cursor']]);
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $second['items']);
        self::assertSame(
            [],
            array_intersect($first['items'], $second['items']),
            'страницы не пересекаются',
        );
    }

    public function testInvalidStatusReturns422(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);

        self::list(self::login(), ['status' => 'unknown']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCompanyAdminForbidden(): void
    {
        self::client();
        UserFactory::createOne([
            'email' => 'registry-admin@test.ru',
            'role' => UserRoleEnum::ADMIN,
            'password' => self::PASSWORD,
        ]);

        self::list(self::login('registry-admin@test.ru'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('GET', CompanyListController::URL);
        self::assertResponseStatusCodeSame(401);
    }
}
