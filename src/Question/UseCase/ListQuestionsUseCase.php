<?php

declare(strict_types=1);

namespace App\Question\UseCase;

use App\Iam\Entity\User;
use App\Question\Presenter\TenderQuestionPresenter;
use App\Question\Service\TenderQuestionService;
use App\Shared\Exception\ConflictException;

/**
 * Список вопросов/ответов по тендеру (FR-1.2.9, GET /tenders/{tenderId}/questions).
 *
 * Query-use-case: вопросы (новые сверху) без пагинации (ограниченный набор).
 * Доступ — право tenders.qa через TenderQaVoter (subject не используется),
 * поэтому видимость самого тендера для компании актора проверяет сервис
 * (FR-1.5.14): чужая невидимая закупка → 404.
 */
final readonly class ListQuestionsUseCase implements QuestionUseCase
{
    public function __construct(
        private TenderQuestionService $questions,
        private TenderQuestionPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, string $tenderId): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $items = [];
        foreach ($this->questions->listForTender($tenderId, $companyId) as $question) {
            $items[] = $this->presenter->single($question);
        }

        return ['items' => $items];
    }
}
