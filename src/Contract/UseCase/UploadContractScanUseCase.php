<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractScanService;
use App\Contract\Entity\Contract;
use App\Document\DocumentService;
use App\Iam\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Приложение скана договора (FR-1.4.7, UC-08a, POST /contracts/{contractId}/scan).
 *
 * Исполнитель прикладывает скан для работы с заказчиком; для многоразового
 * договора (multi_use) один скан действует для многих тендеров. Загружается
 * документ (entity_type=contract, scope=contract) и фиксируется
 * contract_documents. Файл извлекается из multipart-запроса контроллером;
 * party-проверка и оркестрация — ContractScanService::upload. Ответ — в форме
 * openapi Document (контракт эндпоинта) через публичный DocumentService::present.
 * Доступ: любая сторона договора.
 */
final readonly class UploadContractScanUseCase implements ContractUseCase
{
    public function __construct(
        private ContractScanService $scans,
        private DocumentService $documents,
    ) {
    }

    /**
     * @return array<string, mixed> презентация документа (openapi Document)
     */
    public function execute(Contract $contract, User $user, UploadedFile $file, ?string $ip = null): array
    {
        $scan = $this->scans->upload($user, (string) $contract->getId(), $file, $ip);

        return $this->documents->present($user, (string) $scan->getDocumentId());
    }
}
