<?php

declare(strict_types=1);

namespace App\Bid;

use App\Bid\Entity\Bid;

/**
 * Публичное представление заявки (openapi Bid, AM-4).
 *
 * До вскрытия отдаются ТОЛЬКО метаданные (FR-1.2.2): id, tender/lot,
 * supplier_id, status, время, decision_reason. Содержимое (part1, part2_ref,
 * price) в представлении отсутствует — оно зашифровано в encrypted_payload.
 *
 * После вскрытия (FR-1.2.3, UC-06) содержимое расшифровано в decrypted_payload:
 * - заказчик (тенант тендера) видит ПОЛНЫЙ состав заявки (part1, part2_ref,
 *   price_minor/price_basis/vat_rate);
 * - участник видит (в части) только part1 — согласие и характеристики;
 *   цены/документы второй части скрыты до допуска/аукциона.
 */
final readonly class BidPresenter
{
    /**
     * Метаданные заявки до вскрытия (FR-1.2.2). Содержимого нет.
     *
     * @return array<string, mixed>
     */
    public function metadata(Bid $bid): array
    {
        return [
            'id' => (string) $bid->getId(),
            'tender_id' => (string) $bid->getTenderId(),
            'lot_id' => null !== $bid->getLotId() ? (string) $bid->getLotId() : null,
            'supplier_id' => (string) $bid->getSupplierId(),
            'status' => $bid->getStatus()->value,
            'submitted_at' => $bid->getSubmittedAt()?->format('Y-m-d\TH:i:s\Z'),
            'evaluated_at' => $bid->getEvaluatedAt()?->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $bid->getDecisionReason(),
            'created_at' => $bid->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            'payload_encrypted' => true,
        ];
    }

    /**
     * Представление заявки ПОСЛЕ вскрытия (FR-1.2.3). К метаданным добавляется
     * расшифрованное содержимое: заказчику ($isCustomer) — полностью, участнику
     * — только part1. Если содержимое ещё не расшифровано (вскрытие не
     * выполнено) — возвращает метаданные (fallback на FR-1.2.2).
     *
     * @return array<string, mixed>
     */
    public function opened(Bid $bid, bool $isCustomer): array
    {
        $payload = $bid->getDecryptedPayload();
        if (null === $payload) {
            return $this->metadata($bid);
        }

        $view = $this->metadata($bid);
        $view['payload_encrypted'] = false;

        if ($isCustomer) {
            $view['part1'] = $payload['part1'] ?? null;
            $view['part2_ref'] = $payload['part2_ref'] ?? null;
            $view['price_minor'] = $payload['price_minor'] ?? null;
            $view['price_basis'] = $payload['price_basis'] ?? null;
            $view['vat_rate'] = $payload['vat_rate'] ?? null;
        } else {
            $view['part1'] = $payload['part1'] ?? null;
        }

        return $view;
    }
}
