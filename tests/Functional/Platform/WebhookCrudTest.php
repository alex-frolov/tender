<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Platform\Controller\Webhook\WebhookCreateController;
use App\Platform\Controller\Webhook\WebhookDeleteController;
use App\Platform\Controller\Webhook\WebhookDeliveryListController;
use App\Platform\Controller\Webhook\WebhookListController;
use App\Platform\Controller\Webhook\WebhookRotateSecretController;
use App\Platform\Controller\Webhook\WebhookUpdateController;
use App\Platform\Entity\WebhookDelivery;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WebhookFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * webhooks — CRUD и журнал доставок через API (WH-1..7, AM-14).
 *
 * - Создание (POST /webhooks): секрет отдаётся один раз; события валидируются
 *   (формат prefix.action); фильтры payload (WH-7);
 * - Список (GET /webhooks): подписки без секретов;
 * - Обновление (PATCH): url/events/status; отсутствующие в теле поля не меняются;
 *   дубли событий схлопываются; пустой/неверный events и не-http url → 422;
 *   ротация секрета (POST /rotate-secret);
 * - Журнал доставок (GET /webhooks/{id}/deliveries);
 * - Удаление (DELETE /webhooks/{id}): 204;
 * - Права (FR-1.5.10): webhooks.manage — admin; manager/agent — 403;
 *   401 без токена; чужая подписка — 404.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя, дублирующийся на каждый запрос CRUD-цепочки).
 * Прод-код не меняем — правило отключено атрибутом класса,
 * см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('duplicate-query')]
final class WebhookCrudTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->adminToken = $this->adminToken();
    }

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
        return '33.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private function loginAs(string $email): string
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

    private function adminToken(): string
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'wh-admin-'.random_int(1000, 999999).'@test.ru',
        ]);

        return $this->loginAs((string) $user->getEmail());
    }

    public function testFullLifecycleCreateListUpdateRotateDeliveriesDelete(): void
    {
        $token = $this->adminToken;

        // Создание: секрет отдаётся один раз.
        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'https://example.com/hooks/1',
            'events' => ['tender.published', 'tender.updated'],
            'filters' => ['tender_id' => 'tender-42'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $webhookId = $body['id'];
        self::assertIsString($webhookId);
        self::assertSame('https://example.com/hooks/1', $body['url']);
        self::assertSame(['tender.published', 'tender.updated'], $body['events']);
        self::assertSame(['tender_id' => 'tender-42'], $body['filters']);
        self::assertSame('active', $body['status']);
        self::assertIsString($body['secret']);
        self::assertGreaterThanOrEqual(16, \strlen($body['secret']));

        // Список: без секрета.
        $client = self::request('GET', WebhookListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        $first = $list['items'][0];
        self::assertIsArray($first);
        $ids = array_column($list['items'], 'id');
        self::assertContains($webhookId, $ids);
        self::assertArrayNotHasKey('secret', $first);

        // Обновление: status → paused, url.
        $client = self::request('PATCH', str_replace('{webhookId}', $webhookId, WebhookUpdateController::URL), $token, [
            'status' => 'paused',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('paused', $body['status']);

        // Ротация секрета: новый секрет один раз.
        $client = self::request('POST', str_replace('{webhookId}', $webhookId, WebhookRotateSecretController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($webhookId, $body['id']);
        self::assertIsString($body['secret']);

        // Журнал доставок (пустой).
        $client = self::request('GET', str_replace('{webhookId}', $webhookId, WebhookDeliveryListController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);

        // Удаление → 204.
        $client = self::request('DELETE', str_replace('{webhookId}', $webhookId, WebhookDeleteController::URL), $token);
        self::assertResponseStatusCodeSame(204);

        // После удаления список пуст.
        $client = self::request('GET', WebhookListController::URL, $token);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertSame([], $list['items']);
    }

    /**
     * Entity-bound update form (AGENTS.md): в теле только events — url и status
     * сохраняют текущие значения (clearMissing: false); дубли событий схлопываются.
     */
    public function testUpdateKeepsFieldsMissingFromBodyAndDeduplicatesEvents(): void
    {
        $token = $this->adminToken;

        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'https://example.com/hooks/partial',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($created);
        $webhookId = $created['id'];
        self::assertIsString($webhookId);

        $url = str_replace('{webhookId}', $webhookId, WebhookUpdateController::URL);
        $client = self::request('PATCH', $url, $token, [
            'events' => ['tender.updated', 'tender.published', 'tender.updated'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(['tender.updated', 'tender.published'], $body['events']);
        self::assertSame('https://example.com/hooks/partial', $body['url']);
        self::assertSame('active', $body['status']);
    }

    public function testUpdateValidatesUrlAndEvents(): void
    {
        $token = $this->adminToken;

        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'https://example.com/hooks/validate',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($created);
        $webhookId = $created['id'];
        self::assertIsString($webhookId);
        $url = str_replace('{webhookId}', $webhookId, WebhookUpdateController::URL);

        // Пустой список событий — подписка без событий бессмысленна.
        self::request('PATCH', $url, $token, ['events' => []]);
        self::assertResponseStatusCodeSame(422);

        // Неверный формат события.
        self::request('PATCH', $url, $token, ['events' => ['Tender.Published']]);
        self::assertResponseStatusCodeSame(422);

        // Схема url — только http/https.
        self::request('PATCH', $url, $token, ['url' => 'ftp://example.com/hook']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateValidatesEvents(): void
    {
        $token = $this->adminToken;

        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'https://example.com/hooks/2',
            'events' => ['Tender.Published'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'ftp://example.com/hook',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSecretGeneratedWhenNotProvided(): void
    {
        $token = $this->adminToken;

        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'https://example.com/hooks/3',
            'events' => ['auction.bid'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['secret']);
        self::assertGreaterThanOrEqual(16, \strlen($body['secret']));
    }

    public function testManagerAndAgentCannotManageWebhooksReturns403(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();

        $manager = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::MANAGER,
            'email' => 'wh-manager-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'wh-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $managerToken = $this->loginAs((string) $manager->getEmail());
        $agentToken = $this->loginAs((string) $agent->getEmail());

        // manager и agent — webhooks.manage отключено по умолчанию (FR-1.5.10).
        $client = self::request('POST', WebhookCreateController::URL, $managerToken, [
            'url' => 'https://example.com/hooks/4',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client = self::request('GET', WebhookListController::URL, $agentToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request('GET', WebhookListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testForeignWebhookReturns404(): void
    {
        $other = WebhookFactory::createOne();
        $token = $this->adminToken;

        $client = self::request('PATCH', str_replace('{webhookId}', (string) $other->getId(), WebhookUpdateController::URL), $token, [
            'status' => 'paused',
        ]);
        self::assertResponseStatusCodeSame(404);

        $client = self::request('GET', str_replace('{webhookId}', (string) $other->getId(), WebhookDeliveryListController::URL), $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeliveriesListShowsRecords(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'wh-del-'.random_int(1000, 999999).'@test.ru',
        ]);
        $token = $this->loginAs((string) $user->getEmail());

        $webhook = WebhookFactory::createOne(['tenantId' => $company->getId(), 'events' => ['tender.published']]);
        $delivery = new WebhookDelivery(
            webhook: $webhook,
            eventId: Uuid::v4(),
            eventType: 'tender.published',
            payload: '{"event_type":"tender.published"}',
        );
        $delivery->markDead(3, 500, 'HTTP 500');
        static::getContainer()->get(EntityManagerInterface::class)->persist($delivery);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client = self::request('GET', str_replace('{webhookId}', (string) $webhook->getId(), WebhookDeliveryListController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertSame('tender.published', $item['event_type']);
        self::assertSame('dead', $item['status']);
        self::assertSame(3, $item['attempts']);
        self::assertSame(500, $item['last_http_status']);
        self::assertSame('HTTP 500', $item['last_error']);
    }
}
