<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\UseCase\CheckTenderAccessUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Проверка доступа к тендеру (FR-1.5.14, GET /tenders/{tenderId}/access).
 *
 * Для закрытого тендера (access_type=contract_holders) доступен только
 * исполнитель с действующим multi_use-договором с заказчиком; ответ содержит
 * reason из openapi: contract_required / contract_expired / contract_terminated
 * / ok. Для открытого тендера — всегда ok. Доступ: любой сотрудник компании.
 * Оркестрация и презентация — CheckTenderAccessUseCase.
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/access GET).
 */
final class TenderAccessController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/access';

    #[Route(self::URL, name: 'tender_access', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function access(Request $request, string $tenderId, CheckTenderAccessUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, tenderId: $tenderId));
    }
}
