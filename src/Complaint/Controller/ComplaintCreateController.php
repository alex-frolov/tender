<?php

declare(strict_types=1);

namespace App\Complaint\Controller;

use App\Complaint\Form\CreateComplaintType;
use App\Complaint\Input\CreateComplaintInput;
use App\Complaint\UseCase\CreateComplaintUseCase;
use App\Controller\AbstractBaseController;
use App\Security\TenderQaVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подача жалобы по тендеру (FR-1.2.10, POST /tenders/{tenderId}/complaints).
 * Доступ — право tenders.qa (TenderQaVoter::FILE_COMPLAINT). Валидацию тела
 * выполняет CreateComplaintType (422 при невалидных). Контракт: api/openapi.yaml
 * (/tenders/{tenderId}/complaints POST).
 */
final class ComplaintCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/complaints';

    #[Route(self::URL, name: 'complaint_create', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderQaVoter::FILE_COMPLAINT)]
    public function create(Request $request, string $tenderId, CreateComplaintUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(CreateComplaintType::class, $request);
        /** @var CreateComplaintInput $input */
        $input = $form->getData();

        return $this->json(
            $useCase->execute($user, $tenderId, $input, $request->getClientIp()),
            Response::HTTP_CREATED,
        );
    }
}
