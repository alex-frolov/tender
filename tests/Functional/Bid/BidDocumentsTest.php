<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bid;

use App\Bid\Controller\BidAttachDocumentsController;
use App\Bid\Controller\BidSubmitController;
use App\Document\Controller\DocumentUploadController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\DocumentTypeFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Состав части 2 заявки (FR-1.2.1, POST /bids/{bidId}/documents).
 *
 * Документ прикладывается к сущности `bid`, поэтому появиться раньше заявки
 * он не может — состав части 2 задаётся отдельным вызовом после подачи.
 *
 * - свой документ привязывается, ответ — метаданные заявки (содержимое
 *   остаётся зашифрованным);
 * - документ чужой сущности → 422: заказчик не смог бы его открыть;
 * - чужая заявка → 404;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: `n-plus-one`, `duplicate-query` — AuthMiddleware:84 делает SELECT
 * пользователя на каждый HTTP-запрос сценария; см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('duplicate-query')]
final class BidDocumentsTest extends WebTestCase
{
    use TenderLotTrait;

    private const START_MINOR = 10000;

    private static ?KernelBrowser $client = null;

    private Company $supplierCompany;
    private Tender $tender;
    private string $lotId;
    private string $documentTypeId;
    private string $supplierToken;

    /** @var list<string> временные файлы теста */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => self::START_MINOR]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($this->tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($this->tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();

        $this->lotId = self::firstLotId($this->tender);

        $this->supplierCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplier = UserFactory::createOne([
            'companyId' => $this->supplierCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'bid-doc-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->supplierToken = $this->loginAs((string) $supplier->getEmail());

        $this->documentTypeId = (string) DocumentTypeFactory::createOne()->getId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
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
        return '19.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private function tempFile(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('bid-doc-', true).'-'.$name;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function json(string $method, string $url, ?string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request(
            $method,
            $url,
            [],
            [],
            $headers,
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    /**
     * @param array<mixed>                $parameters
     * @param array<string, UploadedFile> $files
     */
    private static function multipart(string $url, string $token, array $parameters, array $files): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request('POST', $url, $parameters, $files, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

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
     * Ещё один тендер в accepting_bids (для сценариев со второй процедурой).
     */
    private function extraAcceptingBidsTender(): Tender
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();

        return $tender;
    }

    /**
     * Контекст: поданная заявка поставщика и документ, приложенный к ней.
     *
     * @return array{bidId: string, documentId: string}
     */
    private function submittedBidWithDocument(): array
    {
        $client = self::json(
            'POST',
            str_replace('{tenderId}', (string) $this->tender->getId(), BidSubmitController::URL),
            $this->supplierToken,
            [
                'supplier_id' => (string) $this->supplierCompany->getId(),
                'lot_id' => $this->lotId,
                'part1' => ['consent' => true],
                'price_minor' => 9000,
                'price_basis' => 'net',
                'vat_rate' => 20,
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        $bidId = $bid['id'];
        self::assertIsString($bidId);

        $client = self::multipart(
            DocumentUploadController::URL,
            $this->supplierToken,
            [
                'document_type_id' => $this->documentTypeId,
                'entity_type' => 'bid',
                'entity_id' => $bidId,
            ],
            ['file' => new UploadedFile($this->tempFile('part2.pdf', 'part-two-content'), 'part2.pdf', 'application/pdf')],
        );
        self::assertResponseStatusCodeSame(201);
        $document = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($document);
        $documentId = $document['id'];
        self::assertIsString($documentId);

        return ['bidId' => $bidId, 'documentId' => $documentId];
    }

    private static function attachUrl(string $bidId): string
    {
        return str_replace('{bidId}', $bidId, BidAttachDocumentsController::URL);
    }

    public function testAttachesOwnDocument(): void
    {
        $ctx = $this->submittedBidWithDocument();

        $client = self::json('POST', self::attachUrl($ctx['bidId']), $this->supplierToken, [
            'document_ids' => [$ctx['documentId']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($ctx['bidId'], $body['id']);
        self::assertSame('submitted', $body['status']);
        // Содержимое заявки до вскрытия закрыто даже от её автора (FR-1.2.2).
        self::assertArrayNotHasKey('part1', $body);
        self::assertArrayNotHasKey('part2_ref', $body);
    }

    public function testDocumentOfAnotherBidIsRejected(): void
    {
        $ctx = $this->submittedBidWithDocument();

        // Документ, приложенный к ДРУГОЙ заявке того же поставщика: сам по себе
        // он доступен, но частью 2 этой заявки быть не может — иначе заказчик
        // после вскрытия открыл бы файл не из той процедуры.
        $otherTender = $this->extraAcceptingBidsTender();
        $client = self::json(
            'POST',
            str_replace('{tenderId}', (string) $otherTender->getId(), BidSubmitController::URL),
            $this->supplierToken,
            [
                'supplier_id' => (string) $this->supplierCompany->getId(),
                'lot_id' => self::firstLotId($otherTender),
                'part1' => ['consent' => true],
                'price_minor' => 9000,
                'price_basis' => 'net',
                'vat_rate' => 20,
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $otherBid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($otherBid);
        self::assertIsString($otherBid['id']);

        $client = self::multipart(
            DocumentUploadController::URL,
            $this->supplierToken,
            [
                'document_type_id' => $this->documentTypeId,
                'entity_type' => 'bid',
                'entity_id' => $otherBid['id'],
            ],
            ['file' => new UploadedFile($this->tempFile('other.pdf', 'other-content'), 'other.pdf', 'application/pdf')],
        );
        self::assertResponseStatusCodeSame(201);
        $foreign = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($foreign);

        self::json('POST', self::attachUrl($ctx['bidId']), $this->supplierToken, [
            'document_ids' => [$foreign['id']],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherCompanyBidIsNotFound(): void
    {
        $ctx = $this->submittedBidWithDocument();

        $otherCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $other = UserFactory::createOne([
            'companyId' => $otherCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'bid-doc-other-'.random_int(1000, 999999).'@test.ru',
        ]);
        $otherToken = $this->loginAs((string) $other->getEmail());

        self::json('POST', self::attachUrl($ctx['bidId']), $otherToken, [
            'document_ids' => [$ctx['documentId']],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthorized(): void
    {
        self::json('POST', self::attachUrl('11111111-1111-4111-8111-111111111111'), null, [
            'document_ids' => [],
        ]);
        self::assertResponseStatusCodeSame(401);
    }
}
