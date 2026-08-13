<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Protocol;

use App\Auction\AuctionLifecycleService;
use App\Document\DocumentGenerator;
use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentOwnerRole;
use App\Document\Entity\Enum\DocumentVisibility;
use App\Shared\Events\EventMessage;
use App\Shared\Exception\NotFoundException;
use App\Tender\Entity\Tender;
use App\Tender\TenderReadService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Генерация протоколов плагина ru-state-procurement через контракт ядра
 * DocumentGenerator (FR-1.2.8): протоколы создаются от имени системы
 * (owner_role=system, is_auto_generated=true) и прикладываются к тендеру.
 *
 * Драйверы (события ядра):
 * - tender.opened            → протокол вскрытия заявок (публичный);
 * - auction.winner_chosen    → итоговый протокол (публичный).
 *
 * Данные для контента — payload события + публичные read-контракты ядра
 * (TenderReadService, AuctionLifecycleService). Типы протоколов
 * регистрируются идемпотентно (auto_generated document_types).
 *
 * Идемпотентность генерации — в DocumentGenerator::generate (повтор для той же
 * (тип, сущность) возвращает существующий документ; at-least-once, NFR-5).
 */
final readonly class RuProtocolGenerator
{
    public function __construct(
        private DocumentGenerator $documents,
        private RuProtocolContentBuilder $builder,
        private TenderReadService $tenders,
        private AuctionLifecycleService $auctions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Регистрация auto_generated-типов протоколов (идемпотентно, FR-1.2.8).
     * Вызывается лениво перед генерацией и командой ru:procurement:install.
     */
    public function ensureDocumentTypes(): void
    {
        $this->documents->ensureDocumentType(
            code: ProtocolType::OPENING->value,
            name: 'Протокол вскрытия заявок',
            ownerRole: DocumentOwnerRole::SYSTEM,
            visibility: DocumentVisibility::PUBLIC,
            sortOrder: 100,
        );
        $this->documents->ensureDocumentType(
            code: ProtocolType::FINAL->value,
            name: 'Итоговый протокол',
            ownerRole: DocumentOwnerRole::SYSTEM,
            visibility: DocumentVisibility::PUBLIC,
            sortOrder: 110,
        );
    }

    /**
     * Протокол вскрытия заявок по событию tender.opened.
     * Возвращает id сгенерированного документа (или null — событие без данных).
     */
    public function generateOpeningProtocol(EventMessage $message): ?string
    {
        $tenderId = $message->payload['tender_id'] ?? null;
        if (!\is_string($tenderId) || !Uuid::isValid($tenderId)) {
            $this->logger->warning('tender.opened without valid tender_id, protocol skipped', ['event_id' => $message->eventId]);

            return null;
        }

        $tender = $this->resolveTender($tenderId, $message->eventId);
        if (null === $tender) {
            return null;
        }

        $this->ensureDocumentTypes();

        $document = $this->documents->generate(
            documentTypeCode: ProtocolType::OPENING->value,
            entityType: DocumentEntityType::TENDER,
            entityId: $tender->getId(),
            title: 'Протокол вскрытия заявок '.$tender->getNumber(),
            content: $this->builder->opening($tender, $message->payload),
            mimeType: 'text/plain',
            extension: 'txt',
            tenantId: $tender->getTenantId(),
            visibility: DocumentVisibility::PUBLIC,
        );

        return (string) $document->getId();
    }

    /**
     * Итоговый протокол по событию auction.winner_chosen (FR-1.3.5).
     * Возвращает id сгенерированного документа (или null — событие без данных).
     */
    public function generateFinalProtocol(EventMessage $message): ?string
    {
        $auctionId = $message->payload['auction_id'] ?? null;
        if (!\is_string($auctionId) || !Uuid::isValid($auctionId)) {
            $this->logger->warning('auction.winner_chosen without valid auction_id, protocol skipped', ['event_id' => $message->eventId]);

            return null;
        }

        $auction = $this->auctions->findById(Uuid::fromString($auctionId));
        if (null === $auction) {
            $this->logger->warning('auction not found for winner_chosen, protocol skipped', ['event_id' => $message->eventId, 'auction_id' => $auctionId]);

            return null;
        }

        $tender = $this->resolveTender((string) $auction->tenderId, $message->eventId);
        if (null === $tender) {
            return null;
        }

        $this->ensureDocumentTypes();

        $document = $this->documents->generate(
            documentTypeCode: ProtocolType::FINAL->value,
            entityType: DocumentEntityType::TENDER,
            entityId: $tender->getId(),
            title: 'Итоговый протокол '.$tender->getNumber(),
            content: $this->builder->final($tender, $message->payload),
            mimeType: 'text/plain',
            extension: 'txt',
            tenantId: $tender->getTenantId(),
            visibility: DocumentVisibility::PUBLIC,
        );

        return (string) $document->getId();
    }

    /**
     * @return Tender|null тендер или null (не найден)
     */
    private function resolveTender(string $tenderId, string $eventId): ?Tender
    {
        try {
            return $this->tenders->resolveTender($tenderId);
        } catch (NotFoundException $e) {
            $this->logger->warning('tender not found for protocol, skipped', ['event_id' => $eventId, 'tender_id' => $tenderId]);

            return null;
        }
    }
}
