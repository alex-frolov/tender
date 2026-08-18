<?php

declare(strict_types=1);

namespace App\Tests\Functional\Supplier;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Supplier\Controller\SupplierGetController;
use App\Supplier\Controller\SupplierProfileGetController;
use App\Supplier\Controller\SupplierProfileUpdateController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Профиль поставщика (FR-1.5.5): GET/PUT /suppliers/profile, GET /suppliers/{id}.
 *
 * - GET профиль: пустые значения до первого сохранения (lazy без записи в БД);
 * - PUT профиль: только admin компании; категории/возможности/документы;
 * - GET по id: карточка с company-данными и рейтингом/проверками.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class SupplierProfileTest extends WebTestCase
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
        return '55.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{supplier: \App\Iam\Entity\Company, admin: string, agent: string}
     */
    private static function supplierContext(): array
    {
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $admin = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'sup-admin-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'sup-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'supplier' => $supplier,
            'admin' => self::loginAs((string) $admin->getEmail()),
            'agent' => self::loginAs((string) $agent->getEmail()),
        ];
    }

    public function testGetProfileReturnsEmptyBeforeFirstSave(): void
    {
        self::client();
        $ctx = self::supplierContext();

        $client = self::request('GET', SupplierProfileGetController::URL, $ctx['agent']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $ctx['supplier']->getId(), $body['company_id']);
        self::assertSame([], $body['categories']);
        self::assertArrayHasKey('verification_status', $body);
    }

    public function testUpdateProfileAndReadByAdmin(): void
    {
        self::client();
        $ctx = self::supplierContext();

        $client = self::request('PUT', SupplierProfileUpdateController::URL, $ctx['admin'], [
            'categories' => ['Строительство', 'Ремонт'],
            'capabilities' => ['Лицензия МЧС'],
            'documents' => [(string) Uuid::v4()],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(['Строительство', 'Ремонт'], $body['categories']);
        self::assertSame(['Лицензия МЧС'], $body['capabilities']);
        self::assertIsString($body['id']);

        $client = self::request('GET', SupplierProfileGetController::URL, $ctx['agent']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(['Строительство', 'Ремонт'], $body['categories']);
    }

    public function testUpdateProfileForbiddenForAgent(): void
    {
        self::client();
        $ctx = self::supplierContext();

        $client = self::request('PUT', SupplierProfileUpdateController::URL, $ctx['agent'], [
            'categories' => ['Охрана'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGetSupplierById(): void
    {
        self::client();
        $ctx = self::supplierContext();

        $client = self::request('PUT', SupplierProfileUpdateController::URL, $ctx['admin'], [
            'categories' => ['Логистика'],
        ]);
        $profile = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($profile);
        self::assertIsString($profile['id']);

        $url = str_replace('{supplierId}', $profile['id'], SupplierGetController::URL);
        $client = self::request('GET', $url, $ctx['agent']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($profile['id'], $body['id']);
        /** @var list<string> $categories */
        $categories = $body['categories'];
        self::assertSame('Логистика', $categories[0]);
    }

    public function testGetSupplierByIdNotFound(): void
    {
        self::client();
        $ctx = self::supplierContext();

        $url = str_replace('{supplierId}', (string) Uuid::v4(), SupplierGetController::URL);
        $client = self::request('GET', $url, $ctx['agent']);
        self::assertResponseStatusCodeSame(404);
    }
}
