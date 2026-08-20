<?php

declare(strict_types=1);

namespace App\Question\UseCase;

use App\Iam\Entity\User;
use App\Question\Input\CreateQuestionInput;
use App\Question\Presenter\TenderQuestionPresenter;
use App\Question\Service\TenderQuestionService;
use App\Shared\Exception\ConflictException;

/**
 * Задать вопрос по тендеру (FR-1.2.9, POST /tenders/{tenderId}/questions).
 *
 * Доступ — право tenders.qa через TenderQaVoter (subject не используется);
 * принадлежность лота тендеру и аудит — TenderQuestionService. Ответ —
 * TenderQuestionPresenter::single (openapi Question).
 */
final readonly class CreateQuestionUseCase implements QuestionUseCase
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
    public function execute(User $user, string $tenderId, CreateQuestionInput $input, ?string $ip = null): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->presenter->single(
            $this->questions->create($tenderId, $input, $companyId, (string) $user->getId(), $ip),
        );
    }
}
