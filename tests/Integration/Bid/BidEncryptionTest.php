<?php

declare(strict_types=1);

namespace App\Tests\Integration\Bid;

use App\Bid\BidPayloadCipher;
use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Entity\Bid;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.1: модель заявок, двухчастность и шифрование до вскрытия
 * (FR-1.2.1/1.2.2). Интеграционный сценарий доказывает ключевой критерий:
 * «payload зашифрован, метаданные видны».
 *
 * - в БД (BYTEA) лежит шифротекст без открытого текста;
 * - метаданные (supplier_id, status, submitted_at, lot_id) — открытые колонки;
 * - расшифровка round-trip возвращает исходное содержимое (part1/part2/price);
 * - инвариант «одна заявка на лот» и статус accepting_bids соблюдаются.
 */
final class BidEncryptionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BidService $bidService;
    private BidPayloadCipher $cipher;
    private WorkflowInterface $tenderWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $service = $container->get(BidService::class);
        if (!$service instanceof BidService) {
            throw new \LogicException('BidService not resolvable');
        }
        $this->bidService = $service;

        $cipher = $container->get(BidPayloadCipher::class);
        if (!$cipher instanceof BidPayloadCipher) {
            throw new \LogicException('BidPayloadCipher not resolvable');
        }
        $this->cipher = $cipher;

        $workflow = $container->get('state_machine.tender');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Tender workflow not resolvable');
        }
        $this->tenderWorkflow = $workflow;
    }

    public function testPayloadEncryptedAtRestAndMetadataVisible(): void
    {
        $supplier = $this->supplierUser();
        $tender = $this->acceptingBidsTender();

        $bid = $this->bidService->submit(
            actor: $supplier,
            tender: $tender,
            lotId: (string) $this->firstLot($tender)->getId(),
            part1: ['consent' => true, 'characteristics' => ['marker' => 'OPEN-TEXT-SECRET']],
            part2Ref: ['doc-11111111-1111-1111-1111-111111111111'],
            priceMinor: 950000,
            priceBasis: PriceBasisEnum::NET,
            vatRate: 20.5,
        );

        // --- метаданные видны (FR-1.2.2): открытые колонки в БД ---
        $row = $this->em->getConnection()
            ->executeQuery('SELECT supplier_id, status, submitted_at, lot_id, encrypted_payload FROM bids WHERE id = :id', [
                'id' => (string) $bid->getId(),
            ])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame((string) $supplier->getCompanyId(), $row['supplier_id']);
        self::assertSame('submitted', $row['status']);
        self::assertNotNull($row['submitted_at']);
        self::assertNotNull($row['lot_id']);

        // --- payload зашифрован (FR-1.2.2): в колонке BYTEA нет открытого текста ---
        $raw = $this->toBinaryString($row['encrypted_payload']);
        foreach (['OPEN-TEXT-SECRET', 'part1', 'part2_ref', 'price_minor', '950000', 'doc-11111111-1111-1111-1111-111111111111'] as $needle) {
            self::assertStringNotContainsString($needle, $raw, 'encrypted_payload must not contain plaintext');
        }

        // шифротекст в БД совпадает с тем, что записал сервис
        self::assertSame($bid->getEncryptedPayload(), $raw);

        // --- round-trip: содержимое расшифровывается до исходного ---
        $this->em->clear();
        $reloaded = $this->em->getRepository(Bid::class)->find($bid->getId());
        self::assertInstanceOf(Bid::class, $reloaded);
        self::assertSame([
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'OPEN-TEXT-SECRET']],
            'part2_ref' => ['doc-11111111-1111-1111-1111-111111111111'],
            'price_minor' => 950000,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ], $this->cipher->decrypt($reloaded->getEncryptedPayload()));

        // --- presenter отдаёт только метаданные (нет содержимого) ---
        $metadata = (new BidPresenter())->metadata($reloaded);
        self::assertSame((string) $reloaded->getId(), $metadata['id']);
        self::assertSame((string) $tender->getId(), $metadata['tender_id']);
        self::assertSame('submitted', $metadata['status']);
        self::assertTrue($metadata['payload_encrypted']);
        self::assertArrayNotHasKey('part1', $metadata);
        self::assertArrayNotHasKey('part2_ref', $metadata);
        self::assertArrayNotHasKey('price_minor', $metadata);
    }

    public function testDuplicateBidPerLotIsRejectedAfterAcceptance(): void
    {
        $supplier = $this->supplierUser();
        $tender = $this->acceptingBidsTender();
        $lotId = (string) $this->firstLot($tender)->getId();

        $this->bidService->submit($supplier, $tender, $lotId, ['consent' => true], [], 900000, null, null);

        // приём закрыт → повторная подача (замена) невозможна
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::CANCEL->value);
        $this->em->flush();

        $this->expectException(ConflictException::class);
        $this->bidService->submit($supplier, $tender, $lotId, ['consent' => true], [], 800000, null, null);
    }

    public function testResubmitWhileAcceptingReplacesBidContent(): void
    {
        $supplier = $this->supplierUser();
        $tender = $this->acceptingBidsTender();
        $lotId = (string) $this->firstLot($tender)->getId();

        $first = $this->bidService->submit($supplier, $tender, $lotId, ['consent' => true], [], 900000, null, null);
        $firstPayload = $first->getEncryptedPayload();
        $firstSubmittedAt = $first->getSubmittedAt();

        // повторная подача до окончания приёма = замена (FR-1.2.5): тот же id,
        // новая цена и новое содержимое, статус снова submitted
        $replaced = $this->bidService->submit($supplier, $tender, $lotId, ['consent' => true], ['doc2'], 800000, null, null);

        self::assertSame((string) $first->getId(), (string) $replaced->getId());
        self::assertSame('submitted', $replaced->getStatus()->value);
        self::assertNotSame($firstPayload, $replaced->getEncryptedPayload());
        self::assertNotSame($firstSubmittedAt, $replaced->getSubmittedAt());

        // в БД одна заявка на лот (инвариант «одна заявка на лот» сохранён)
        $rawCount = $this->em->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM bids WHERE tender_id = :tender AND lot_id = :lot AND supplier_id = :supplier', [
                'tender' => (string) $tender->getId(),
                'lot' => $lotId,
                'supplier' => (string) $supplier->getCompanyId(),
            ])
            ->fetchOne();
        self::assertIsNumeric($rawCount);
        self::assertSame(1, (int) $rawCount);

        $payload = $this->cipher->decrypt($replaced->getEncryptedPayload());
        self::assertSame(800000, $payload['price_minor']);
        self::assertSame(['doc2'], $payload['part2_ref']);
    }

    public function testSubmitWhenNotAcceptingBidsIsRejected(): void
    {
        $supplier = $this->supplierUser();
        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $this->customerCompany()->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);
        // статус — published (приём ещё не начат)

        $this->expectException(ConflictException::class);
        $this->bidService->submit($supplier, $tender, null, ['consent' => true], [], null, null, null);
    }

    private function supplierUser(): User
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();

        return UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
    }

    private function firstLot(Tender $tender): Lot
    {
        $lot = $tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        return $lot;
    }

    private function customerCompany(): Company
    {
        return CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
    }

    /**
     * Тендер в статусе accepting_bids (через workflow, FR-1.1.4).
     */
    private function acceptingBidsTender(): Tender
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $this->customerCompany()->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->em->flush();

        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        return $tender;
    }

    /**
     * Приведение BYTEA к строке байт (pdo_pgsql может отдать resource или hex).
     */
    private function toBinaryString(mixed $value): string
    {
        if (\is_resource($value)) {
            $content = stream_get_contents($value);
            if (false === $content) {
                throw new \RuntimeException('Cannot read bytea resource');
            }

            return $content;
        }

        if (\is_string($value) && str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            if (false === $decoded) {
                throw new \RuntimeException('Cannot decode hex bytea');
            }

            return $decoded;
        }

        if (\is_string($value)) {
            return $value;
        }

        throw new \RuntimeException('Unexpected bytea value type');
    }
}
