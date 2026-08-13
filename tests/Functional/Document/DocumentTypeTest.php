<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Document\Controller\DocumentTypeCreateController;
use App\Document\Controller\DocumentTypeDeactivateController;
use App\Document\Controller\DocumentTypeListController;
use App\Document\Controller\DocumentTypeUpdateController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tests\Factory\DocumentTypeFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.2.7, AM-8: справочник document_types, управляется суперадмином.
 * - list() — активные типы (справочник виден всем);
 * - create — суперадмин; 201; дубль code → 409;
 * - update — суперадмин; изменение полей;
 * - deactivate — суперадмин; тип скрывается из активного списка;
 * - не-суперадмин → 403.
 */
final class DocumentTypeTest extends WebTestCase
{
    private const PLATFORM_EMAIL = 'sa-doctype@test.ru';
    private const PASSWORD = 'secret123';

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
        return '21.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function platformAdmin(): User
    {
        return UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'name' => 'Суперадмин',
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
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
     * @param array<mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = []): KernelBrowser
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

    public function testListDocumentTypesReturnsActiveTypes(): void
    {
        self::client();
        self::platformAdmin();
        DocumentTypeFactory::createOne(['code' => 'dt_list_code', 'name' => 'Тип списка']);
        DocumentTypeFactory::new(['code' => 'dt_inactive_code', 'name' => 'Неактивный'])->inactive()->create();
        $token = self::login();

        $client = self::request('GET', DocumentTypeListController::URL, $token, null);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $codes = array_column($body['items'], 'code');
        self::assertContains('dt_list_code', $codes);
        self::assertNotContains('dt_inactive_code', $codes);
    }

    public function testPlatformAdminCreatesDocumentType(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();

        $client = self::request('POST', DocumentTypeCreateController::URL, $token, [
            'code' => 'dt_created',
            'name' => 'Протокол заседания',
            'owner_role' => 'customer',
            'visibility' => 'private',
            'required' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('dt_created', $body['code']);
        self::assertSame('customer', $body['owner_role']);
        self::assertTrue($body['required']);
        self::assertTrue($body['active']);
        self::assertFalse($body['auto_generated']);
    }

    public function testCreateDuplicateCodeReturns409(): void
    {
        self::client();
        self::platformAdmin();
        DocumentTypeFactory::createOne(['code' => 'dt_duplicate']);
        $token = self::login();

        $client = self::request('POST', DocumentTypeCreateController::URL, $token, [
            'code' => 'dt_duplicate',
            'name' => 'Дубль',
            'owner_role' => 'customer',
            'visibility' => 'public',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPlatformAdminUpdatesDocumentType(): void
    {
        self::client();
        self::platformAdmin();
        $type = DocumentTypeFactory::createOne(['code' => 'dt_update_me', 'name' => 'Старое имя']);
        $token = self::login();

        $url = str_replace('{documentTypeId}', (string) $type->getId(), DocumentTypeUpdateController::URL);
        $client = self::request('PUT', $url, $token, ['name' => 'Новое имя', 'required' => true]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Новое имя', $body['name']);
        self::assertTrue($body['required']);
    }

    public function testPlatformAdminDeactivatesDocumentType(): void
    {
        self::client();
        self::platformAdmin();
        $type = DocumentTypeFactory::createOne(['code' => 'dt_deactivate_me', 'name' => 'К деактивации']);
        $token = self::login();

        $url = str_replace('{documentTypeId}', (string) $type->getId(), DocumentTypeDeactivateController::URL);
        $client = self::request('DELETE', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertFalse($body['active']);

        // скрыт из активного списка
        $client = self::request('GET', DocumentTypeListController::URL, $token, null);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertNotContains('dt_deactivate_me', array_column($list['items'], 'code'));
    }

    public function testNonAdminCannotManageDocumentTypes(): void
    {
        self::client();
        $user = UserFactory::createOne(['role' => UserRoleEnum::ADMIN]);
        UserFactory::createOne(['role' => UserRoleEnum::PLATFORM_ADMIN]); // суперадмин существует, но логинится другой
        $token = self::login((string) $user->getEmail());

        $client = self::request('POST', DocumentTypeCreateController::URL, $token, [
            'code' => 'dt_denied',
            'name' => 'Запрещено',
            'owner_role' => 'customer',
            'visibility' => 'public',
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
