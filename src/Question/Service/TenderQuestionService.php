<?php

declare(strict_types=1);

namespace App\Question\Service;

use App\Question\Entity\TenderQuestion;
use App\Question\Input\CreateQuestionInput;
use App\Question\Repository\TenderQuestionRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Вопросы/ответы по тендеру (tender_questions, FR-1.2.9).
 *
 * - create(): задать вопрос (участник/заказчик, право tenders.qa);
 *   лот валидируется принадлежностью тендеру через TenderReadService;
 * - listForTender(): вопросы тендера (новые сверху).
 *
 * Тендер резолвится ТОЛЬКО через resolveVisibleTender (FR-1.5.14): право
 * tenders.qa есть у любого админа компании и субъекта не имеет, поэтому
 * без проверки видимости Q&A чужой (в т.ч. черновой) закупки читались бы
 * и пополнялись по одному лишь id. Невидимый тендер → 404.
 */
final readonly class TenderQuestionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private TenderQuestionRepository $questions,
        private TenderReadService $tenders,
    ) {
    }

    /**
     * Создание вопроса по тендеру (POST /tenders/{tenderId}/questions).
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     *                                                 или невидим компании
     * @throws ConflictException                       если лот не принадлежит тендеру
     */
    public function create(string $tenderId, CreateQuestionInput $input, Uuid $companyId, string $actorId, ?string $ip = null): TenderQuestion
    {
        $tender = $this->tenders->resolveVisibleTender($tenderId, $companyId);
        $lotId = null !== $input->lotId && '' !== $input->lotId
            ? $this->tenders->resolveLot($tender->getId(), $input->lotId)?->getId()
            : null;

        $question = new TenderQuestion(
            tenderId: $tender->getId(),
            lotId: $lotId,
            text: trim($input->text),
        );

        $this->em->persist($question);
        $this->em->flush();

        $this->audit->record(
            action: 'tender.question_created',
            entityType: 'tender_question',
            entityId: (string) $question->getId(),
            tenantId: (string) $tender->getTenantId(),
            actorType: 'user',
            actorId: $actorId,
            after: ['tender_id' => $tenderId, 'text' => $question->getText()],
            ip: $ip,
        );

        return $question;
    }

    /**
     * Вопросы тендера, видимого компании актора (GET /tenders/{tenderId}/questions).
     *
     * @return list<TenderQuestion>
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     *                                                 или невидим компании
     */
    public function listForTender(string $tenderId, Uuid $companyId): array
    {
        $tender = $this->tenders->resolveVisibleTender($tenderId, $companyId);

        return $this->questions->listForTender($tender->getId());
    }
}
