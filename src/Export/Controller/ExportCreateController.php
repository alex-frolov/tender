<?php

declare(strict_types=1);

namespace App\Export\Controller;

use App\Controller\AbstractBaseController;
use App\Export\Form\CreateExportType;
use App\Export\Input\CreateExportInput;
use App\Export\UseCase\CreateExportUseCase;
use App\Security\ExportVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Запрос фонового экспорта (UC-31, POST /exports).
 * Доступ — право exports.export. Валидацию выполняет форма CreateExportType,
 * оркестрацию — CreateExportUseCase; ответ 202 {job_id, status}. Контракт:
 * api/openapi.yaml (/exports POST).
 */
final class ExportCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/exports';

    #[Route(self::URL, name: 'export_create', methods: [Request::METHOD_POST])]
    #[IsGranted(ExportVoter::EXPORT)]
    public function create(Request $request, CreateExportUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(CreateExportType::class, $request);
        /** @var CreateExportInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_ACCEPTED);
    }
}
