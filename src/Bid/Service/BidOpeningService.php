<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\BidOpeningService as BidOpeningServiceContract;
use App\Bid\BidPayloadCipher;
use App\Bid\Entity\Bid;
use App\Bid\Repository\BidRepository;
use App\Infrastructure\Metrics\BidMetricsCollector;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Реализация публичного write-контракта модуля Bid: вскрытие заявок
 * (см. App\Bid\BidOpeningService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Безопасность (FR-1.2.2): до вскрытия расшифрованное содержимое не хранится
 * (decrypted_payload = null) и не отдаётся. Сам encrypted_payload не изменяется
 * — расшифрованный контент «замораживается» отдельной колонкой, аудит-след
 * цел. Отозванные (withdrawn) заявки не вскрываются.
 */
final readonly class BidOpeningService implements BidOpeningServiceContract
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private BidRepository $bids,
        private BidPayloadCipher $cipher,
        private BidMetricsCollector $metrics,
    ) {
    }

    /**
     * Исход пишется в bid_opening_total{outcome} (opened | skipped | failed):
     * просрочка вскрытия в бизнес-терминах ловит и мёртвую очередь, и
     * потерянное сообщение, и ошибку самого вскрытия.
     */
    public function open(string $tenderId, ?string $ip = null): void
    {
        try {
            /** @var Tender|null $tender */
            $tender = $this->em->getRepository(Tender::class)->find($tenderId);
            if (null === $tender || null !== $tender->getBidsOpenedAt()
                || TenderStatusEnum::ACCEPTING_BIDS !== $tender->getStatus()) {
                $this->metrics->openingFinished(BidMetricsCollector::OPENING_SKIPPED);

                return;
            }

            $this->doOpen($tender, $ip);
            $this->metrics->openingFinished(BidMetricsCollector::OPENING_OPENED);
        } catch (\Throwable $e) {
            // До ретрая messenger'ом; повторная доставка инкрементит failed ещё раз.
            $this->metrics->openingFinished(BidMetricsCollector::OPENING_FAILED);

            throw $e;
        }
    }

    private function doOpen(Tender $tender, ?string $ip): void
    {
        $submitted = $this->bids->listSubmittedForTender($tender->getId());
        $openedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($submitted as $bid) {
            $bid->setDecryptedPayload($this->decrypt($bid));
        }
        $tender->setBidsOpenedAt($openedAt);

        $this->em->persist(new OutboxEvent(
            eventType: 'tender.opened',
            payload: [
                'tender_id' => (string) $tender->getId(),
                'number' => $tender->getNumber(),
                'bids_end' => $tender->getTimeline()['bids_end'] ?? null,
                'bids_count' => \count($submitted),
                'opened_at' => $openedAt->format('Y-m-d\TH:i:s\Z'),
            ],
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            tenantId: (string) $tender->getTenantId(),
        ));
        $this->em->flush();

        $this->audit->record(
            action: 'tender.opened',
            entityType: 'tender',
            entityId: (string) $tender->getId(),
            tenantId: (string) $tender->getTenantId(),
            after: [
                'status' => $tender->getStatus()->value,
                'bids_opened_at' => $openedAt->format('Y-m-d\TH:i:s\Z'),
                'bids_count' => \count($submitted),
                'payloads_decrypted' => \count($submitted),
            ],
            ip: $ip,
        );
    }

    /**
     * Расшифровка содержимого заявки (FR-1.2.3).
     *
     * @return array<string, mixed>
     */
    private function decrypt(Bid $bid): array
    {
        return $this->cipher->decrypt($bid->getEncryptedPayload());
    }
}
