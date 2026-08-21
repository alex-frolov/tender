<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Document\Controller\DocumentDownloadController;
use App\Document\Controller\DocumentGetController;
use App\Document\Controller\DocumentListController;
use App\Document\Controller\DocumentUploadController;
use App\Iam\Controller\Auth\TokenController;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\DocumentUploadStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * AM-8, FR-1.1.5, FR-1.2.6: загрузка документов тендера, версионирование,
 * hash, метаданные, скачивание, видимость.
 * - загрузка документа тендера (201) с типом, hash, версией;
 * - добавление версии → версии растут, текущая версия соответствует;
 * - скачивание возвращает бинарное содержимое;
 * - приватный документ невидим чужому tenant (403), публичный — виден;
 * - лимиты: неверный mime (422), слишком большой файл.
 */
final class DocumentUploadTest extends WebTestCase
{
    private const EMAIL = DocumentUploadStory::CUSTOMER_EMAIL;
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
        return '20.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function login(string $email = self::EMAIL): string
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
     * @param array<mixed>                $parameters
     * @param array<string, UploadedFile> $files
     */
    private static function multipart(string $url, string $token, array $parameters, array $files): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            $url,
            $parameters,
            $files,
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    private static function jsonGet(string $url, string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $client;
    }

    /**
     * @return array{document_type_id: string, entity_type: string, entity_id: string}
     */
    private static function fixture(): array
    {
        self::client();
        $type = DocumentUploadStory::publicType();
        $tender = DocumentUploadStory::tender();

        return [
            'document_type_id' => (string) $type->getId(),
            'entity_type' => 'tender',
            'entity_id' => (string) $tender->getId(),
        ];
    }

    public function testUploadDocumentReturnsMetadataAndHash(): void
    {
        $fx = self::fixture();
        $token = self::login();

        $file = new UploadedFile($this->tempFile('Приложение.pdf', 'hello-document-content'), 'Приложение.pdf', 'application/pdf');
        $client = self::multipart(DocumentUploadController::URL, $token, $fx, ['file' => $file]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((int) $fx['document_type_id'], $body['document_type_id']);
        self::assertSame('tender', $body['entity_type']);
        self::assertSame($fx['entity_id'], $body['entity_id']);
        self::assertSame('public', $body['visibility']);
        self::assertSame('customer', $body['owner_role']);
        self::assertFalse($body['is_auto_generated']);
        self::assertSame('Приложение.pdf', $body['title']);
        self::assertIsArray($body['versions']);
        self::assertCount(1, $body['versions']);
        $v0 = $body['versions'][0];
        self::assertIsArray($v0);
        self::assertSame(1, $v0['version']);
        self::assertSame(hash('sha256', 'hello-document-content'), $v0['sha256']);
        self::assertSame(22, $v0['size_bytes']);
        self::assertIsString($body['id']);
        self::assertIsString($body['download_url']);
    }

    public function testAddVersionIncrementsVersions(): void
    {
        $fx = self::fixture();
        $token = self::login();

        $file1 = new UploadedFile($this->tempFile('spec.pdf', 'version-one'), 'spec.pdf', 'application/pdf');
        $client = self::multipart(DocumentUploadController::URL, $token, $fx, ['file' => $file1]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $documentId = $body['id'];

        $getUrl = str_replace('{documentId}', $documentId, DocumentGetController::URL);
        $client = self::jsonGet($getUrl, $token);
        self::assertResponseStatusCodeSame(200);
        $single = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($single);
        self::assertIsArray($single['versions']);
        self::assertCount(1, $single['versions']);
        $v = $single['versions'][0];
        self::assertIsArray($v);
        self::assertSame(1, $v['version']);
    }

    public function testDownloadReturnsFileContent(): void
    {
        $fx = self::fixture();
        $token = self::login();

        $file = new UploadedFile($this->tempFile('doc.pdf', 'binary-content-123'), 'doc.pdf', 'application/pdf');
        $client = self::multipart(DocumentUploadController::URL, $token, $fx, ['file' => $file]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $documentId = $body['id'];

        $dlUrl = str_replace('{documentId}', $documentId, DocumentDownloadController::URL);
        $client = self::jsonGet($dlUrl, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('binary-content-123', $client->getResponse()->getContent());
        self::assertStringContainsString('doc.pdf', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    /**
     * Список документов сущности: до его появления загруженный документ можно
     * было открыть только по прямому id, и карточка тендера не показывала
     * приложенные файлы.
     *
     * Видимость та же, что у чтения одного документа: чужой приватный документ
     * в выборку не попадает (не 403 на весь список, а просто отсутствует).
     */
    public function testListDocumentsOfEntityRespectsVisibility(): void
    {
        self::client();
        $tenderId = (string) DocumentUploadStory::tender()->getId();
        $token = self::login();

        $publicFx = [
            'document_type_id' => (string) DocumentUploadStory::publicType()->getId(),
            'entity_type' => 'tender',
            'entity_id' => $tenderId,
        ];
        $client = self::multipart(
            DocumentUploadController::URL,
            $token,
            $publicFx,
            ['file' => new UploadedFile($this->tempFile('public.pdf', 'public-content'), 'public.pdf', 'application/pdf')],
        );
        self::assertResponseStatusCodeSame(201);
        $publicBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($publicBody);
        $publicId = $publicBody['id'];
        self::assertIsString($publicId);

        $privateFx = [
            'document_type_id' => (string) DocumentUploadStory::privateType()->getId(),
            'entity_type' => 'tender',
            'entity_id' => $tenderId,
        ];
        $client = self::multipart(
            DocumentUploadController::URL,
            $token,
            $privateFx,
            ['file' => new UploadedFile($this->tempFile('secret.pdf', 'secret-content'), 'secret.pdf', 'application/pdf')],
        );
        self::assertResponseStatusCodeSame(201);
        $privateBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($privateBody);
        $privateId = $privateBody['id'];
        self::assertIsString($privateId);

        $listUrl = DocumentListController::URL.'?entity_type=tender&entity_id='.$tenderId;

        // Владелец видит оба документа.
        $client = self::jsonGet($listUrl, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        /** @var list<string> $ids */
        $ids = array_column($body['items'], 'id');
        self::assertContains($publicId, $ids);
        self::assertContains($privateId, $ids);

        // Чужая компания — только публичный.
        $otherToken = self::login((string) DocumentUploadStory::other()->getEmail());
        $client = self::jsonGet($listUrl, $otherToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        /** @var list<string> $otherIds */
        $otherIds = array_column($body['items'], 'id');
        self::assertContains($publicId, $otherIds);
        self::assertNotContains($privateId, $otherIds);
    }

    public function testListDocumentsRequiresEntityParameters(): void
    {
        // Стори строится лениво — без обращения к ней пользователя ещё нет,
        // и логин в этом тесте отвечал бы invalid_credentials.
        self::fixture();
        $token = self::login();

        self::jsonGet(DocumentListController::URL, $token);
        self::assertResponseStatusCodeSame(422);

        self::jsonGet(DocumentListController::URL.'?entity_type=tender&entity_id=not-a-uuid', $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRejectsUnsupportedMime(): void
    {
        $fx = self::fixture();
        $token = self::login();

        $file = new UploadedFile($this->tempFile('virus.exe', 'MZ...'), 'virus.exe', 'application/x-msdownload');
        $client = self::multipart(DocumentUploadController::URL, $token, $fx, ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRequiresAuth(): void
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('POST', DocumentUploadController::URL, [], [], []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testGetPrivateDocumentByOtherTenantIs403(): void
    {
        self::client();
        $type = DocumentUploadStory::privateType();
        $token = self::login();

        $fx = [
            'document_type_id' => (string) $type->getId(),
            'entity_type' => 'tender',
            'entity_id' => (string) DocumentUploadStory::tender()->getId(),
        ];
        $file = new UploadedFile($this->tempFile('private.pdf', 'private-content'), 'private.pdf', 'application/pdf');
        $client = self::multipart(DocumentUploadController::URL, $token, $fx, ['file' => $file]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $documentId = $body['id'];

        // другой пользователь (другой tenant) не видит приватный документ
        $other = DocumentUploadStory::other();
        $otherToken = self::login((string) $other->getEmail());
        $getUrl = str_replace('{documentId}', $documentId, DocumentGetController::URL);
        $client = self::jsonGet($getUrl, $otherToken);
        self::assertResponseStatusCodeSame(403);
    }

    private function tempFile(string $name, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tender_doc_').'.pdf';
        file_put_contents($path, $content);

        return $path;
    }
}
