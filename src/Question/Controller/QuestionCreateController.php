<?php

declare(strict_types=1);

namespace App\Question\Controller;

use App\Controller\AbstractBaseController;
use App\Question\Form\CreateQuestionType;
use App\Question\Input\CreateQuestionInput;
use App\Question\UseCase\CreateQuestionUseCase;
use App\Security\TenderQaVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Задать вопрос по тендеру (FR-1.2.9, POST /tenders/{tenderId}/questions).
 * Доступ — право tenders.qa (TenderQaVoter). Валидацию тела выполняет
 * CreateQuestionType (422 при невалидных). Контракт: api/openapi.yaml
 * (/tenders/{tenderId}/questions POST).
 */
final class QuestionCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/questions';

    #[Route(self::URL, name: 'question_create', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderQaVoter::ASK)]
    public function create(Request $request, string $tenderId, CreateQuestionUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(CreateQuestionType::class, $request);
        /** @var CreateQuestionInput $input */
        $input = $form->getData();

        return $this->json(
            $useCase->execute($user, $tenderId, $input, $request->getClientIp()),
            Response::HTTP_CREATED,
        );
    }
}
