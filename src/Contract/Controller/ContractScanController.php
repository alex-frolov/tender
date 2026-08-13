<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Entity\Contract;
use App\Contract\UseCase\UploadContractScanUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Exception\ValidationException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Приложение скана договора (FR-1.4.7, UC-08a, POST /contracts/{contractId}/scan).
 * Исполнитель прикладывает скан для работы с заказчиком; для многоразового
 * договора (multi_use) один скан действует для многих тендеров. Загружается
 * документ (entity_type=contract, scope=contract) и фиксируется contract_documents.
 * Файл из multipart-запроса извлекается здесь (HTTP-адаптация), оркестрация —
 * UploadContractScanUseCase. Доступ: любая сторона договора (party-проверка в
 * сервисе). Контракт: api/openapi.yaml (/contracts/{contractId}/scan POST).
 */
final class ContractScanController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts/{contractId}/scan';

    #[Route(self::URL, name: 'contract_scan', methods: [Request::METHOD_POST])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function scan(
        Request $request,
        #[MapEntity(mapping: ['contractId' => 'id'])]
        Contract $contract,
        UploadContractScanUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ValidationException('file is required');
        }

        return $this->json($useCase->execute(
            contract: $contract,
            user: $user,
            file: $file,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
