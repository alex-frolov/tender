<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Entity\Tender;
use App\Tender\Form\TenderRateType;
use App\Tender\Input\RateTenderInput;
use App\Tender\UseCase\RateTenderUseCase;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Оценка исполнения заказа (FR-1.1.10, UC-10c, POST /tenders/{tenderId}/rating).
 * Заказчик выставляет оценку (1–10) ПОСЛЕ завершения исполнения (DONE/DONE_BY_CLAIM);
 * хранится в тендере (execution_rating). Тендер загружается через #[MapEntity]
 * (субъект TenderVoter::RATE). Валидация — форма TenderRateType, оркестрация —
 * RateTenderUseCase. Доступ: tenders.rate (admin/manager).
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/rating POST).
 */
final class TenderRateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/rating';

    #[Route(self::URL, name: 'tender_rate', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::RATE)]
    public function rate(
        Request $request,
        #[MapEntity(mapping: ['tenderId' => 'id'])]
        Tender $tender,
        RateTenderUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(TenderRateType::class, $request);
        /** @var RateTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            tender: $tender,
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
