<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\Form\TenderCreateType;
use App\Tender\Input\CreateTenderInput;
use App\Tender\UseCase\CreateTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание тендера-черновика (FR-1.1.1). Только сотрудник компании-заказчика
 * с правом tenders.create (admin/manager; agent — 403, domain/permissions.md).
 * Доступ через TenderVoter (permission-проверка, см. AGENTS.md). Валидацию
 * входных данных выполняет форма TenderCreateType (422 при невалидных),
 * оркестрацию — CreateTenderUseCase (прикладной слой модуля); ошибки
 * (ApiException) в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/tenders POST).
 */
final class TenderCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders';

    #[Route(self::URL, name: 'tender_create', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::CREATE)]
    public function create(Request $request, CreateTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TenderCreateType::class, $request);
        /** @var CreateTenderInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
