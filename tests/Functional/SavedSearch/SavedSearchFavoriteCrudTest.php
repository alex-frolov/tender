<?php

declare(strict_types=1);

namespace App\Tests\Functional\SavedSearch;

use App\Favorite\Controller\FavoriteCreateController;
use App\Favorite\Controller\FavoriteDeleteController;
use App\Favorite\Controller\FavoriteListController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\SavedSearch\Controller\SavedSearchCreateController;
use App\SavedSearch\Controller\SavedSearchDeleteController;
use App\SavedSearch\Controller\SavedSearchListController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\FavoriteFactory;
use App\Tests\Factory\SavedSearchFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 6.7: сохранённые поиски и избранное — CRUD через API (F-A5/A6, AM-12).
 *
 * - Сохранённый поиск: POST /saved-searches (name/filters/digest_period), GET
 *   список, DELETE ?savedSearchId= → 204; 422 на пустые name/filters;
 * - Избранное: POST /favorites (entity_type tender/lot, entity_id, note), GET
 *   список, DELETE ?favoriteId= → 204; дубликат → 409 duplicate_favorite;
 *   422 на неверный entity_type/entity_id;
 * - Права: search.save/favorites.manage — common, доступны всем ролям (в т.ч.
 *   agent); 401 без токена; чужая запись → 404.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class SavedSearchFavoriteCrudTest extends WebTestCase
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
        return '45.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    /**
     * @return array{token: string, user: object, company: object}
     */
    private static function actor(UserRoleEnum $role): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => $role,
            'email' => 'fav-'.$role->value.'-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'user' => $user, 'company' => $company];
    }

    public function testSavedSearchLifecycleCreateListDelete(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        // Создание.
        $client = self::request('POST', SavedSearchCreateController::URL, $token, [
            'name' => 'Строительство в Москве',
            'filters' => ['query' => 'строительство', 'region' => 'msk'],
            'digest_period' => 'daily',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $savedSearchId = $body['id'];
        self::assertIsString($savedSearchId);
        self::assertSame('Строительство в Москве', $body['name']);
        self::assertSame(['query' => 'строительство', 'region' => 'msk'], $body['filters']);
        self::assertSame('daily', $body['digest_period']);
        self::assertTrue($body['active']);
        self::assertIsString($body['created_at']);

        // Список — шаблон виден.
        $client = self::request('GET', SavedSearchListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        self::assertIsArray($list['items'][0]);
        self::assertSame($savedSearchId, $list['items'][0]['id']);

        // Удаление → 204, список пуст.
        $client = self::request('DELETE', SavedSearchDeleteController::URL.'?savedSearchId='.$savedSearchId, $token);
        self::assertResponseStatusCodeSame(204);

        $client = self::request('GET', SavedSearchListController::URL, $token);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertSame([], $list['items']);
    }

    public function testSavedSearchCreateValidatesNameFiltersDigestPeriod(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        // Пустое имя.
        $client = self::request('POST', SavedSearchCreateController::URL, $token, [
            'name' => '',
            'filters' => ['query' => 'строительство'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Пустые фильтры.
        $client = self::request('POST', SavedSearchCreateController::URL, $token, [
            'name' => 'Поиск',
            'filters' => [],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Невалидная периодичность.
        $client = self::request('POST', SavedSearchCreateController::URL, $token, [
            'name' => 'Поиск',
            'filters' => ['query' => 'строительство'],
            'digest_period' => 'monthly',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testFavoriteLifecycleAddListDelete(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];
        $tenderId = Uuid::v4();

        // Добавление с заметкой.
        $client = self::request('POST', FavoriteCreateController::URL, $token, [
            'entity_type' => 'tender',
            'entity_id' => (string) $tenderId,
            'note' => 'важный тендер',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $favoriteId = $body['id'];
        self::assertIsString($favoriteId);
        self::assertSame('tender', $body['entity_type']);
        self::assertSame((string) $tenderId, $body['entity_id']);
        self::assertSame('важный тендер', $body['note']);
        self::assertIsString($body['created_at']);

        // Список — запись видна.
        $client = self::request('GET', FavoriteListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        self::assertIsArray($list['items'][0]);
        self::assertSame($favoriteId, $list['items'][0]['id']);

        // Удаление → 204, список пуст.
        $client = self::request('DELETE', FavoriteDeleteController::URL.'?favoriteId='.$favoriteId, $token);
        self::assertResponseStatusCodeSame(204);

        $client = self::request('GET', FavoriteListController::URL, $token);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertSame([], $list['items']);
    }

    public function testFavoriteDuplicateReturns409(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];
        $tenderId = Uuid::v4();

        $data = ['entity_type' => 'tender', 'entity_id' => (string) $tenderId];
        $client = self::request('POST', FavoriteCreateController::URL, $token, $data);
        self::assertResponseStatusCodeSame(201);

        // Повторное добавление той же сущности → 409 duplicate_favorite.
        $client = self::request('POST', FavoriteCreateController::URL, $token, $data);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('duplicate_favorite', $body['code'] ?? null);
    }

    public function testFavoriteCreateValidatesEntityTypeAndId(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        // Неверный entity_type.
        $client = self::request('POST', FavoriteCreateController::URL, $token, [
            'entity_type' => 'contract',
            'entity_id' => (string) Uuid::v4(),
        ]);
        self::assertResponseStatusCodeSame(422);

        // Невалидный entity_id.
        $client = self::request('POST', FavoriteCreateController::URL, $token, [
            'entity_type' => 'lot',
            'entity_id' => 'not-a-uuid',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCanManageOwnSearchesAndFavorites(): void
    {
        // search.save/favorites.manage — common, включены всем ролям по умолчанию.
        self::client();
        $token = self::actor(UserRoleEnum::AGENT)['token'];

        $client = self::request('POST', SavedSearchCreateController::URL, $token, [
            'name' => 'Мой поиск',
            'filters' => ['query' => 'ремонт'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $client = self::request('POST', FavoriteCreateController::URL, $token, [
            'entity_type' => 'lot',
            'entity_id' => (string) Uuid::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);

        $client = self::request('GET', SavedSearchListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('GET', FavoriteListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', SavedSearchListController::URL, '');
        self::assertResponseStatusCodeSame(401);
        $client = self::request('GET', FavoriteListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testForeignSearchReturns404(): void
    {
        self::client();
        $other = SavedSearchFactory::createOne();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        $client = self::request('DELETE', SavedSearchDeleteController::URL.'?savedSearchId='.(string) $other->getId(), $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignFavoriteReturns404(): void
    {
        self::client();
        $other = FavoriteFactory::createOne();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        $client = self::request('DELETE', FavoriteDeleteController::URL.'?favoriteId='.(string) $other->getId(), $token);
        self::assertResponseStatusCodeSame(404);
    }
}
