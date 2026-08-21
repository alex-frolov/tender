<?php

declare(strict_types=1);

namespace App\Question\Controller;

use App\Controller\AbstractBaseController;
use App\Question\Form\AnswerQuestionType;
use App\Question\Input\AnswerQuestionInput;
use App\Question\UseCase\AnswerQuestionUseCase;
use App\Security\TenderQaVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ответ заказчика на вопрос по тендеру (FR-1.2.9,
 * POST /tenders/{tenderId}/questions/{questionId}/answer).
 *
 * Доступ — право tenders.qa (TenderQaVoter::ANSWER); сторону (отвечает только
 * заказчик процедуры) проверяет TenderQuestionService: право есть и у
 * участников, они задают вопросы этим же правом. Валидацию тела выполняет
 * AnswerQuestionType (422 при невалидных). Контракт: api/openapi.yaml
 * (/tenders/{tenderId}/questions/{questionId}/answer POST).
 */
final class QuestionAnswerController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/questions/{questionId}/answer';

    #[Route(self::URL, name: 'question_answer', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderQaVoter::ANSWER)]
    public function answer(
        Request $request,
        string $tenderId,
        string $questionId,
        AnswerQuestionUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);

        $form = $this->formInput(AnswerQuestionType::class, $request);
        /** @var AnswerQuestionInput $input */
        $input = $form->getData();

        return $this->json(
            $useCase->execute($user, $tenderId, $questionId, $input, $request->getClientIp()),
        );
    }
}
