<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bid;

use App\Bid\BidPayloadCipher;
use App\Bid\Entity\Bid;
use App\Bid\Entity\BidDocument;
use App\Bid\Entity\Enum\BidPartEnum;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentType;
use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentOwnerRole;
use App\Document\Entity\Enum\DocumentScope;
use App\Document\Entity\Enum\DocumentVisibility;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Модель заявки (FR-1.2.1/1.2.2): двухчастность, секретность содержимого
 * (зашифрованный payload в сущности, метаданные в открытых полях), подача.
 * Единичные тесты без контейнера — сущности строятся напрямую.
 */
final class BidModelTest extends TestCase
{
    private const VAT_BPS = 2000;

    private function tender(): Tender
    {
        return new Tender(
            number: 'T-1',
            title: 'Закупка',
            procedureType: ProcedureTypeEnum::AUCTION,
            currency: 'RUB',
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            customerId: Uuid::v4(),
            createdBy: Uuid::v4(),
            lawType: LawTypeEnum::COMMERCIAL,
            nmckMinor: 10000,
            noStartPrice: false,
        );
    }

    private function lot(Tender $tender): Lot
    {
        $lot = new Lot(
            tender: $tender,
            title: 'Лот 1',
            priceNetMinor: 10000,
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            currency: 'RUB',
            number: 1,
        );
        $tender->addLot($lot);

        return $lot;
    }

    /**
     * Заявка на уровне тендера (lot_id = null) с tenant'ом тендера.
     */
    private function bid(): Bid
    {
        $tender = $this->tender();

        return new Bid($tender->getId(), null, Uuid::v4(), $tender->getTenantId());
    }

    private function document(Tender $tender): Document
    {
        $type = new DocumentType(
            code: 'bid_consent',
            name: 'Согласие',
            ownerRole: 'executor',
            visibility: 'private',
        );

        return new Document(
            documentType: $type,
            entityType: DocumentEntityType::BID,
            entityId: Uuid::v4(),
            title: 'consent.pdf',
            ownerRole: DocumentOwnerRole::EXECUTOR,
            visibility: DocumentVisibility::PRIVATE,
            scope: DocumentScope::TENDER,
            tenantId: $tender->getTenantId(),
            createdBy: Uuid::v4(),
        );
    }

    public function testConstructorSetsMetadata(): void
    {
        $tender = $this->tender();
        $lot = $this->lot($tender);
        $supplierId = Uuid::v4();

        $bid = new Bid($tender->getId(), $lot->getId(), $supplierId, $tender->getTenantId());

        self::assertSame($tender->getTenantId(), $bid->getTenantId());
        self::assertSame($tender->getId(), $bid->getTenderId());
        self::assertSame($lot->getId(), $bid->getLotId());
        self::assertSame($supplierId, $bid->getSupplierId());
        self::assertSame(BidStatusEnum::DRAFT, $bid->getStatus());
        self::assertNull($bid->getSubmittedAt());
    }

    public function testSubmitWithoutEncryptedPayloadIsRejected(): void
    {
        $bid = $this->bid();

        $this->expectException(\LogicException::class);
        $bid->submit();
    }

    public function testSubmitSetsSubmittedStatus(): void
    {
        $bid = $this->bid();
        $cipher = new BidPayloadCipher('unit-key-0123456789abcdef0123456789');

        $bid->setEncryptedPayload($cipher->encrypt([
            'part1' => ['consent' => true],
            'part2_ref' => [],
            'price_minor' => null,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ]));
        $bid->submit();

        self::assertSame(BidStatusEnum::SUBMITTED, $bid->getStatus());
        self::assertNotNull($bid->getSubmittedAt());
    }

    public function testEntityStoresCiphertextNotPlaintext(): void
    {
        $bid = $this->bid();
        $cipher = new BidPayloadCipher('unit-key-0123456789abcdef0123456789');
        $secret = 'SECRET-CHARACTERISTICS-MARKER';

        $bid->setEncryptedPayload($cipher->encrypt([
            'part1' => ['characteristics' => ['marker' => $secret]],
            'part2_ref' => [],
            'price_minor' => 950000,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ]));

        self::assertStringNotContainsString($secret, $bid->getEncryptedPayload());
        self::assertSame([
            'part1' => ['characteristics' => ['marker' => $secret]],
            'part2_ref' => [],
            'price_minor' => 950000,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ], $cipher->decrypt($bid->getEncryptedPayload()));
    }

    public function testTwoPartDocumentsAttachToBid(): void
    {
        $tender = $this->tender();
        $bid = $this->bid();
        $document = $this->document($tender);

        $bid->addDocument(new BidDocument($bid, $document->getId(), BidPartEnum::PART_1));
        $bid->addDocument(new BidDocument($bid, $document->getId(), BidPartEnum::PART_2, isEncrypted: false));

        self::assertCount(2, $bid->getDocuments());
        foreach ($bid->getDocuments() as $bidDocument) {
            self::assertInstanceOf(BidDocument::class, $bidDocument);
            self::assertSame($document->getId(), $bidDocument->getDocumentId());
        }
    }

    public function testWithdrawSubmittedBidSetsWithdrawnAndReason(): void
    {
        $bid = $this->bid();
        $bid->setEncryptedPayload((new BidPayloadCipher('unit-key-0123456789abcdef0123456789'))->encrypt([
            'part1' => ['consent' => true],
            'part2_ref' => [],
            'price_minor' => null,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ]));
        $bid->submit();

        $bid->withdraw('Сняли заявку');

        self::assertSame(BidStatusEnum::WITHDRAWN, $bid->getStatus());
        self::assertSame('Сняли заявку', $bid->getDecisionReason());
        // evaluated_at — поле рассмотрения (допуск/отклонение), при отзыве не трогаем
        self::assertNull($bid->getEvaluatedAt());
    }

    public function testWithdrawNonSubmittedBidIsRejected(): void
    {
        $bid = $this->bid();

        $this->expectException(\LogicException::class);
        $bid->withdraw('Нельзя');
    }
}
