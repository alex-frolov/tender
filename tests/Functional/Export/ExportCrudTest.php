<?php

declare(strict_types=1);

namespace App\Tests\Functional\Export;

use App\Export\Controller\ExportCreateController;
use App\Export\Controller\ExportDownloadController;
use App\Export\Controller\ExportStatusController;
use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\ExportJobMessage;
use App\Export\ExportJobMessageHandler;
use App\Export\Storage\ExportFileStorage;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\RolePermissionCache;
use App\Iam\Service\RolePermissionService;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ExportJobFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * экспорт через API (UC-31, AM-15, F-A7).
 *
 * - POST /exports: 202 {job_id, status: queued}; валидация export_type/format;
 * - фоновое формирование: ExportJobMessageHandler → файл (csv) → ready;
 * - GET /exports/{id}: статус + download_url (только для ready);
 * - GET /exports/{id}/download: файл с заголовками; 409 для не-ready;
 * - tenant-изоляция: чужая задача → 404; 401 без токена;
 * - доступ exports.export (common): admin/manager/agent — 202 по умолчанию;
 *   403 при отключении права для роли.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ExportCrudTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    /** @var array{company: Company, user: User, token: string} */
    private array $adminCtx;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();
        // Кэш наборов прав (Redis) общий с dev: мог быть собран до добавления
        // exports.export (миграция 20260812140000) — инвалидируем (как DashboardTest).
        self::getContainer()->get(RolePermissionCache::class)->clear();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->adminCtx = self::adminContext();
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

    /**
     * @return array{company: Company, user: User, token: string}
     */
    private function adminContext(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'exp-admin-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['company' => $company, 'user' => $user, 'token' => $this->loginAs((string) $user->getEmail())];
    }

    /**
     * Запуск фоновой генерации вручную (транспорт `exports` в тестах in-memory,
     * обработчик вызывается напрямую — как воркер messenger:consume exports).
     */
    private static function processJob(string $jobId): void
    {
        $handler = self::getContainer()->get(ExportJobMessageHandler::class);
        $handler->__invoke(new ExportJobMessage($jobId));
    }

    public function testFullLifecycleCreateProcessStatusDownload(): void
    {
        $ctx = $this->adminCtx;
        TenderFactory::createOne(['customerId' => $ctx['company']->getId(), 'title' => 'API export row']);

        // Создание → 202 {job_id, status}.
        $client = self::request('POST', ExportCreateController::URL, $ctx['token'], [
            'export_type' => 'tenders',
            'format' => 'csv',
            'filters' => ['status' => 'draft'],
        ]);
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['job_id']);
        self::assertSame('queued', $body['status']);
        $jobId = $body['job_id'];

        // До обработки: статус queued, download_url нет.
        $client = self::request('GET', str_replace('{jobId}', $jobId, ExportStatusController::URL), $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('queued', $body['status']);
        self::assertNull($body['download_url']);
        self::assertSame('tenders', $body['export_type']);
        self::assertSame('csv', $body['format']);

        // Фоновая генерация (воркер).
        self::processJob($jobId);

        // Статус ready + download_url.
        $client = self::request('GET', str_replace('{jobId}', $jobId, ExportStatusController::URL), $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(ExportJobStatusEnum::READY->value, $body['status']);
        self::assertIsString($body['download_url']);
        self::assertStringContainsString('/download', $body['download_url']);

        // Скачивание: csv с BOM, заголовком и данными.
        $client = self::request('GET', str_replace('{jobId}', $jobId, ExportDownloadController::URL), $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        self::assertStringContainsString('id,number,title,status', $content);
        self::assertStringContainsString('API export row', $content);

        // Очистка файла с диска (БД откатывается автоматически).
        $job = ExportJobFactory::repository()->find($jobId);
        self::assertNotNull($job);
        self::assertNotNull($job->getStoragePath());
        self::getContainer()->get(ExportFileStorage::class)->delete($job->getStoragePath());
    }

    public function testDownloadNotReadyReturns409(): void
    {
        $ctx = $this->adminCtx;
        $job = ExportJobFactory::createOne([
            'tenantId' => $ctx['company']->getId(),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::CSV,
        ]);

        $client = self::request('GET', str_replace('{jobId}', (string) $job->getId(), ExportDownloadController::URL), $ctx['token']);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('export_not_ready', $body['code']);
    }

    public function testCreateValidatesTypeAndFormat(): void
    {
        $token = $this->adminCtx['token'];

        $client = self::request('POST', ExportCreateController::URL, $token, [
            'export_type' => 'invoices',
            'format' => 'csv',
        ]);
        self::assertResponseStatusCodeSame(422);

        $client = self::request('POST', ExportCreateController::URL, $token, [
            'export_type' => 'tenders',
            'format' => 'docx',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForeignJobReturns404(): void
    {
        $other = ExportJobFactory::createOne();
        $token = $this->adminCtx['token'];

        $client = self::request('GET', str_replace('{jobId}', (string) $other->getId(), ExportStatusController::URL), $token);
        self::assertResponseStatusCodeSame(404);

        $client = self::request('GET', str_replace('{jobId}', (string) $other->getId(), ExportDownloadController::URL), $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request('POST', ExportCreateController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testManagerAndAgentCanExportByDefault(): void
    {
        self::client();
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();

        $manager = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::MANAGER,
            'email' => 'exp-manager-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'exp-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $managerToken = $this->loginAs((string) $manager->getEmail());
        $agentToken = $this->loginAs((string) $agent->getEmail());

        // exports.export — common: включено по умолчанию (миграция 20260812140000).
        $client = self::request('POST', ExportCreateController::URL, $managerToken, [
            'export_type' => 'tenders',
            'format' => 'csv',
        ]);
        self::assertResponseStatusCodeSame(202);

        $client = self::request('POST', ExportCreateController::URL, $agentToken, [
            'export_type' => 'contracts',
            'format' => 'xlsx',
        ]);
        self::assertResponseStatusCodeSame(202);
    }

    public function testDeniedWhenPermissionDisabledForRole(): void
    {
        self::client();
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $admin = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'exp-deny-admin-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'exp-deny-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        // Отключаем exports.export для роли agent (FR-1.5.15: применяется немедленно).
        self::getContainer()->get(RolePermissionService::class)->update(
            $admin,
            RolePermissionRoleEnum::AGENT,
            ['exports.export' => false],
        );

        $token = $this->loginAs((string) $agent->getEmail());
        $client = self::request('POST', ExportCreateController::URL, $token, [
            'export_type' => 'tenders',
            'format' => 'csv',
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
