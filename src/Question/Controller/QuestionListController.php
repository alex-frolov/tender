<?php

declare(strict_types=1);

namespace App\Question\Controller;

use App\Controller\AbstractBaseController;
use App\Question\UseCase\ListQuestionsUseCase;
use App\Security\TenderQaVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список вопросов/ответов по тендеру (FR-1.2.9, GET /tenders/{tenderId}/questions).
 * Доступ — право tenders.qa (TenderQaVoter). Контракт: api/openapi.yaml
 * (/tenders/{tenderId}/questions GET).
 */
final class QuestionListController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/questions';

    #[Route(self::URL, name: 'question_list', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderQaVoter::LIST)]
    public function list(Request $request, string $tenderId, ListQuestionsUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $tenderId));
    }
}
