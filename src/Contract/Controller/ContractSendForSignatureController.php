<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Entity\Contract;
use App\Contract\UseCase\SendContractForSignatureUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ContractVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отправка договора на подписание (C1, draft → pending_signature, FR-1.4.3).
 * Инициирует заказчик. Доступ: право contracts.sign (заказчик, admin/manager)
 * ИЛИ исполнитель договора (ContractVoter::SIGN, subject-based). Контракт
 * загружается через #[MapEntity] — субъект для Voter'а.
 * Событие contract.pending_signature. Оркестрация — SendContractForSignatureUseCase.
 */
final class ContractSendForSignatureController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts/{contractId}/send-for-signature';

    #[Route(self::URL, name: 'contract_send_for_signature', methods: [Request::METHOD_POST])]
    #[IsGranted(ContractVoter::SIGN, subject: 'contract')]
    public function sendForSignature(
        Request $request,
        #[MapEntity(mapping: ['contractId' => 'id'])]
        Contract $contract,
        SendContractForSignatureUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            contract: $contract,
            user: $user,
            ip: $request->getClientIp(),
        ));
    }
}
