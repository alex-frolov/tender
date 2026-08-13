<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractScanService;
use App\Contract\Entity\Contract;
use App\Iam\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Приложение скана договора (FR-1.4.7, UC-08a, POST /contracts/{contractId}/scan).
 *
 * Исполнитель прикладывает скан для работы с заказчиком; для многоразового
 * договора (multi_use) один скан действует для многих тендеров. Загружается
 * документ (entity_type=contract, scope=contract) и фиксируется
 * contract_documents. Файл извлекается из multipart-запроса контроллером;
 * party-проверка и оркестрация — ContractScanService::upload. Доступ: любая
 * сторона договора.
 */
final readonly class UploadContractScanUseCase implements ContractUseCase
{
    public function __construct(private ContractScanService $scans)
    {
    }

    /**
     * @return array{id: string, contract_id: string, document_id: string, uploaded_by: string}
     */
    public function execute(Contract $contract, User $user, UploadedFile $file, ?string $ip = null): array
    {
        $scan = $this->scans->upload($user, (string) $contract->getId(), $file, $ip);

        return [
            'id' => (string) $scan->getId(),
            'contract_id' => (string) $scan->getContractId(),
            'document_id' => (string) $scan->getDocumentId(),
            'uploaded_by' => $scan->getUploadedBy(),
        ];
    }
}
