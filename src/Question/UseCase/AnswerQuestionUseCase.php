<?php

declare(strict_types=1);

namespace App\Question\UseCase;

use App\Iam\Entity\User;
use App\Question\Input\AnswerQuestionInput;
use App\Question\Presenter\TenderQuestionPresenter;
use App\Question\Service\TenderQuestionService;
use App\Shared\Exception\ConflictException;

/**
 * Ответить на вопрос по тендеру (FR-1.2.9,
 * POST /tenders/{tenderId}/questions/{questionId}/answer).
 *
 * Доступ — право tenders.qa через TenderQaVoter; принадлежность процедуры
 * (отвечает только заказчик) и аудит — TenderQuestionService. Ответ —
 * TenderQuestionPresenter::single (openapi Question).
 */
final readonly class AnswerQuestionUseCase implements QuestionUseCase
{
    public function __construct(
        private TenderQuestionService $questions,
        private TenderQuestionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация вопроса (openapi Question)
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(
        User $user,
        string $tenderId,
        string $questionId,
        AnswerQuestionInput $input,
        ?string $ip = null,
    ): array {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->presenter->single(
            $this->questions->answer($tenderId, $questionId, $input, $companyId, (string) $user->getId(), $ip),
        );
    }
}
