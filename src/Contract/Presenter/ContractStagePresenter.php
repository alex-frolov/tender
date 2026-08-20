<?php

declare(strict_types=1);

namespace App\Contract\Presenter;

use App\Contract\Entity\ContractStage;

/**
 * Публичное представление этапа исполнения (openapi ContractStage, Contract).
 *
 * Поля строго по схеме ContractStage из api/openapi.yaml. Используется
 * UseCase'ами модуля для формирования ответа (создание этапа UC-10).
 */
final readonly class ContractStagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(ContractStage $stage): array
    {
        return [
            'id' => (string) $stage->getId(),
            'contract_tender_id' => (string) $stage->getContractTenderId(),
            'number' => $stage->getNumber(),
            'title' => $stage->getTitle(),
            'amount_minor' => $stage->getAmountMinor(),
            'due_at' => $stage->getDueAt()?->format('Y-m-d\TH:i:s\Z'),
            'status' => $stage->getStatus(),
            'accepted_at' => $stage->getAcceptedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
