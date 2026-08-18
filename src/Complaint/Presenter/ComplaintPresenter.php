<?php

declare(strict_types=1);

namespace App\Complaint\Presenter;

use App\Complaint\Entity\Complaint;

/**
 * Публичное представление жалобы по тендеру (openapi Complaint).
 *
 * Поля строго по схеме Complaint из api/openapi.yaml; document_ids —
 * дополнительное выводимое поле (приложения жалобы, additive к контракту).
 */
final readonly class ComplaintPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Complaint $complaint): array
    {
        return [
            'id' => (string) $complaint->getId(),
            'tender_id' => (string) $complaint->getTenderId(),
            'lot_id' => null !== $complaint->getLotId() ? (string) $complaint->getLotId() : null,
            'status' => $complaint->getStatus()->value,
            'text' => $complaint->getText(),
            'ground' => $complaint->getGround(),
            'document_ids' => $complaint->getDocumentIds(),
            'resolution' => $complaint->getResolution(),
        ];
    }
}
