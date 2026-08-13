<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Entity\Contract;
use App\Contract\Form\ContractSignType;
use App\Contract\Input\SignContractInput;
use App\Contract\UseCase\SignContractUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ContractVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подписание договора (C2, FR-1.4.3, AM-9 POST /contracts/{id}/sign).
 * Подписывают ОБЕ стороны (party=customer|supplier; ЭП-заглушка). Доступ:
 * право contracts.sign (заказчик, admin/manager) ИЛИ исполнитель договора
 * (ContractVoter::SIGN, subject-based); какая именно сторона и в каком статусе —
 * в ContractService через SignContractUseCase (409 для не-той стороны/повторной
 * подписи). При подписях обеих сторон → статус signed + событие contract.signed.
 * Валидацию body выполняет форма ContractSignType (422).
 * Контракт: api/openapi.yaml (/contracts/{contractId}/sign POST).
 */
final class ContractSignController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts/{contractId}/sign';

    #[Route(self::URL, name: 'contract_sign', methods: [Request::METHOD_POST])]
    #[IsGranted(ContractVoter::SIGN, subject: 'contract')]
    public function sign(
        Request $request,
        #[MapEntity(mapping: ['contractId' => 'id'])]
        Contract $contract,
        SignContractUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(ContractSignType::class, $request);
        /** @var SignContractInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            contract: $contract,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
