<?php

declare(strict_types=1);

namespace App\Tender;

use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use App\Shared\Input\InputValue;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\CancellationReasonEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\Exception\RatingNotAllowedException;
use App\Tender\Input\CreateTenderInput;
use App\Tender\Input\LotInput;
use App\Tender\Input\UpdateTenderInput;
use App\Tender\Repository\TenderRepository;
use App\Tender\Service\TenderTimelineScheduler;
use App\Tender\Service\TenderTransaction;
use App\Tender\Timeline\TimelineRules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * CRUD тендера (FR-1.1.1): создание черновика, список, карточка, правка.
 * Публикация и авто-переходы по таймлайну (FR-1.1.4) — тоже здесь.
 *
 * Сервис — оркестратор: валидация (workflow-guards, причины, инварианты лотов),
 * планирование авто-переходов по таймлайну (TimelineMessage). Транзакционный
 * «хвост» (persist/flush + append-only аудит FR-1.8, генерация номера) вынесен
 * во внутренний support-класс `Tender\Service\TenderTransaction`.
 *
 * - Тендер создаётся в статусе draft; number генерируется сервисом.
 * - Тендер принадлежит компании актора (tenantId = customerId при создании);
 *   список/карточка/правка — только в рамках компании актора (tenant-изоляция).
 * - Правка допустима до окончания приёма заявок (status phase <= accepting_bids);
 *   change_reason не хранится в тендере — пишется в аудит.
 * - Инвариант суммы лотов (FR-1.1.7) проверяется при создании через
 *   Tender::assertLotsSumInvariant().
 *
 * Валидация входных данных (обязательность, enum, диапазоны) — в формах
 * TenderCreateType/TenderUpdateType (контроллер), разбор id и UUID + бизнес-правила
 * — ЗДЕСЬ. Ошибки бросаются как ApiException (ValidationException/ConflictException/
 * NotFoundException) и единообразно превращаются в JSON подписчиком
 * JsonApiExceptionSubscriber — контроллеры остаются тонкими.
 */
final class TenderService
{
    public function __construct(
        private readonly TimelineRules $timelineRules,
        private readonly TenderRepository $tenders,
        private readonly TenderTransaction $transaction,
        private readonly TenderTimelineScheduler $scheduler,
        #[Autowire(service: 'state_machine.tender')]
        private readonly WorkflowInterface $tenderWorkflow,
    ) {
    }

    /**
     * Создание тендера-черновика (FR-1.1.1). Валидация входных данных — в форме
     * TenderCreateType; преобразование enum и генерация number — здесь.
     *
     * @throws ValidationException если lots пуст или неверный customer_id
     * @throws ConflictException   если актор без компании
     */
    public function create(User $actor, CreateTenderInput $input, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $customerId = InputValue::uuid($input->customerId, 'customer_id');

        $tender = new Tender(
            number: $this->transaction->nextNumber(),
            title: $input->title,
            procedureType: $this->procedureType($input->procedureType),
            currency: $input->currency,
            vatRateBps: $this->vatBps($input->vatRate),
            priceBasis: $this->priceBasis($input->priceBasis),
            customerId: $customerId,
            createdBy: $actor->getId(),
            lawType: $input->lawType ? $this->lawType($input->lawType) : LawTypeEnum::COMMERCIAL,
            nmckMinor: $input->nmckMinor,
            noStartPrice: $input->noStartPrice,
            description: $input->description,
            region: $input->region,
            accessType: $input->accessType ? $this->accessType($input->accessType) : AccessTypeEnum::OPEN,
            requiredContractTypeId: $input->requiredContractTypeId ? InputValue::uuid($input->requiredContractTypeId, 'required_contract_type_id') : null,
            timeline: $input->timeline,
        );

        if ([] === $input->lots) {
            throw new ValidationException('tender must have at least one lot');
        }

        foreach ($input->lots as $i => $lotInput) {
            $tender->addLot($this->buildLot($tender, $lotInput, $i));
        }

        $tender->assertLotsSumInvariant();

        $this->transaction->commitCreated($tender, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * Список тендеров компании актора (FR-1.1.1).
     * Необязательный фильтр по статусу. Лоты eager-загружаются репозиторием
     * (fix N+1 на lotCount()/aggregatedStatus()).
     *
     * @return list<Tender>
     */
    public function list(User $actor, ?string $status = null): array
    {
        $companyId = InputValue::companyId($actor);

        $statusEnum = null;
        if (null !== $status && '' !== $status) {
            $statusEnum = TenderStatusEnum::tryFrom($status);
            if (null === $statusEnum) {
                throw new ValidationException('invalid status');
            }
        }

        return $this->tenders->listForTenant($companyId, $statusEnum);
    }

    /**
     * Агрегированные статусы тендеров компании актора при мультилоте
     * (FR-1.1.3, вариант C). Читается DB-агрегацией (TenderRepository),
     * без загрузки коллекций лотов.
     *
     * @return array<string, TenderStatusEnum> тендер_id → агрегированный статус
     */
    public function aggregatedStatuses(User $actor): array
    {
        return $this->tenders->aggregatedStatuses(InputValue::companyId($actor));
    }

    /**
     * Карточка тендера (FR-1.1.1). Возвращает тендер только в рамках компании актора.
     *
     * @throws NotFoundException если тендер не найден в компании актора
     */
    public function get(User $actor, string $tenderId): Tender
    {
        return $this->resolveTender(InputValue::companyId($actor), $tenderId);
    }

    /**
     * Правка тендера (FR-1.1.1) до окончания приёма заявок.
     * Изменяются только указанные поля (null = не менять); change_reason пишется
     * в аудит. Инвариант суммы лотов здесь не пересчитывается — правка не трогает лоты.
     *
     * @throws NotFoundException если тендер не найден в компании актора
     * @throws ConflictException если тендер уже нельзя редактировать
     */
    public function update(User $actor, string $tenderId, UpdateTenderInput $input, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $tender = $this->resolveTender($companyId, $tenderId);
        $this->assertEditable($tender);

        $before = [
            'title' => $tender->getTitle(),
            'description' => $tender->getDescription(),
            'region' => $tender->getRegion(),
            'timeline' => $tender->getTimeline(),
        ];

        if (null !== $input->title) {
            $tender->setTitle($input->title);
        }
        if (null !== $input->description) {
            $tender->setDescription('' === $input->description ? null : $input->description);
        }
        if (null !== $input->region) {
            $tender->setRegion('' === $input->region ? null : $input->region);
        }
        if (null !== $input->timeline) {
            $tender->setTimeline([] === $input->timeline ? null : $input->timeline);
        }

        $after = [
            'title' => $tender->getTitle(),
            'description' => $tender->getDescription(),
            'region' => $tender->getRegion(),
            'timeline' => $tender->getTimeline(),
        ];

        $this->transaction->commitUpdated($tender, $before, $after, $input->changeReason, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * Публикация тендера (FR-1.1.4): draft → published через workflow.
     *
     * - Таймлайн (сроки) рассчитывается TimelineRules («сроки из плагина»);
     * - на момент bids_start планируется авто-переход published → accepting_bids
     *   через TimelineMessage (Redis-транспорт, DelayStamp) — FR-1.1.4;
     * - публикация требует лотов и соблюдения инварианта суммы (FR-1.1.7);
     * - каждая мутация пишет append-only запись в аудит (FR-1.8).
     *
     * @throws NotFoundException        если тендер не найден в компании актора
     * @throws StateTransitionException если тендер не в статусе draft
     * @throws ValidationException      если нет лотов или сумма лотов ≠ НМЦК
     */
    public function publish(User $actor, string $tenderId, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $tender = $this->resolveTender($companyId, $tenderId);

        $transition = TenderStatusTransition::PUBLISH->value;
        if (!$this->tenderWorkflow->can($tender, $transition)) {
            throw new StateTransitionException('Only draft tenders can be published');
        }

        $this->assertPublishable($tender);

        $timeline = $this->timelineRules->calculate($tender);
        $tender->setTimeline($timeline);

        $before = $tender->getStatus();
        $this->tenderWorkflow->apply($tender, $transition);

        $this->scheduler->scheduleStartBidAcceptance($tender, $timeline);
        $this->scheduler->scheduleBidOpening($tender, $timeline);

        $this->transaction->commitPublished($tender, $before, $timeline, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * Отзыв публикации (B3, FR-1.1.3): published → withdrawn через workflow.
     * Разрешён ТОЛЬКО до старта приёма заявок (до accepting_bids); после — только
     * отмена (cancel). Не терминален: withdrawn → published (перепубликация) или
     * withdrawn → cancelled. Причина отзыва — свободный текст, пишется в аудит
     * и передаётся в событие tender.withdrawn.
     *
     * @throws NotFoundException        если тендер не найден в компании актора
     * @throws StateTransitionException если тендер не в статусе published
     * @throws ValidationException      если причина отзыва не указана
     */
    public function withdraw(User $actor, string $tenderId, string $reason, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $tender = $this->resolveTender($companyId, $tenderId);

        if ('' === trim($reason)) {
            throw new ValidationException('reason is required');
        }

        $transition = TenderStatusTransition::WITHDRAW->value;
        if (!$this->tenderWorkflow->can($tender, $transition)) {
            throw new StateTransitionException('Only published tenders (before bid acceptance) can be withdrawn');
        }

        $before = $tender->getStatus();
        $this->tenderWorkflow->apply($tender, $transition);

        $this->transaction->commitWithdrawn($tender, $before, $reason, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * Отмена тендера с причиной (FR-1.1.8): любой активный/withdrawn статус →
     * cancelled через workflow. Причина обязательна: код из CancellationReasonEnum;
     * при code=other обязателен свободный текст. Причина сохраняется в тендере
     * (cancellation_reason_code/text), аудите и передаётся в событие tender.cancelled.
     *
     * @throws NotFoundException        если тендер не найден в компании актора
     * @throws StateTransitionException если переход к cancelled недопустим из текущего статуса
     * @throws ValidationException      если причина не указана или code=other без текста
     */
    public function cancel(User $actor, string $tenderId, ?string $reasonCode, ?string $reasonText, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $tender = $this->resolveTender($companyId, $tenderId);

        $code = $this->cancellationReason($reasonCode);
        if ($code->requiresText() && (null === $reasonText || '' === trim($reasonText))) {
            throw new ValidationException('cancellation_reason_text is required when code is other');
        }

        $transition = TenderStatusTransition::CANCEL->value;
        if (!$this->tenderWorkflow->can($tender, $transition)) {
            throw new StateTransitionException('Tender cannot be cancelled from current status');
        }

        $before = $tender->getStatus();
        $tender->cancel($code, $reasonText);
        $this->tenderWorkflow->apply($tender, $transition);

        $this->transaction->commitCancelled($tender, $before, $code, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * Оценка исполнения заказа (FR-1.1.10, UC-10c, POST /tenders/{tenderId}/rating).
     * Заказчик выставляет оценку (int 1–10) ПОСЛЕ завершения исполнения
     * (DONE / DONE_BY_CLAIM); оценка хранится в тендере (execution_rating,
     * агрегированная при мультилоте), доступна в аналитике и карточке
     * исполнителя. Повторная оценка перезаписывает.
     *
     * @throws NotFoundException   если тендер не найден в компании актора
     * @throws ConflictException   если тендер ещё не завершён (rating_not_allowed)
     * @throws ValidationException если rating вне диапазона 1..10
     */
    public function rate(User $actor, string $tenderId, ?int $rating, ?string $ip = null): Tender
    {
        $companyId = InputValue::companyId($actor);
        $tender = $this->resolveTender($companyId, $tenderId);

        if (null !== $rating && ($rating < 1 || $rating > 10)) {
            throw new ValidationException('execution_rating must be between 1 and 10');
        }

        $aggregated = $tender->aggregatedStatus();
        if (!\in_array($aggregated, [TenderStatusEnum::CLOSED], true)) {
            throw new RatingNotAllowedException();
        }

        $before = $tender->getExecutionRating();
        $tender->setExecutionRating($rating);

        $this->transaction->commitRated($tender, $before, $rating, $actor, $companyId, $ip);

        return $tender;
    }

    /**
     * @throws ValidationException если нет лотов или сумма лотов ≠ НМЦК
     */
    private function assertPublishable(Tender $tender): void
    {
        if (0 === $tender->lotCount()) {
            throw new ValidationException('tender must have at least one lot to publish');
        }

        $tender->assertLotsSumInvariant();
    }

    /**
     * Создать лот из входных данных; vat_rate/price_basis/currency наследуются от тендера.
     */
    private function buildLot(Tender $tender, LotInput $input, int $index): Lot
    {
        $executionStartAt = null;
        if (null !== $input->executionStartAt && '' !== $input->executionStartAt) {
            try {
                $executionStartAt = new \DateTimeImmutable($input->executionStartAt, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new ValidationException('execution_start_at must be a valid date-time');
            }
        }

        return new Lot(
            tender: $tender,
            title: $input->title,
            priceNetMinor: $input->priceNetMinor ?? 0,
            vatRateBps: $this->vatBps($input->vatRate ?? $this->percent($tender->getVatRateBps())),
            priceBasis: $input->priceBasis ? $this->priceBasis($input->priceBasis) : $tender->getPriceBasis(),
            currency: $tender->getCurrency(),
            number: $input->number ?? $index + 1,
            quantity: $input->quantity,
            unit: $input->unit,
            deliveryTerms: $input->deliveryTerms,
            executionStartAt: $executionStartAt,
            tradeEndLeadHours: $input->tradeEndLeadHours,
            securityPercent: $input->securityPercent,
        );
    }

    /**
     * @throws NotFoundException
     */
    private function resolveTender(Uuid $companyId, string $tenderId): Tender
    {
        if (!Uuid::isValid($tenderId)) {
            throw new NotFoundException('Tender not found');
        }

        /** @var Tender|null $tender */
        $tender = $this->tenders->createQueryBuilder('t')
            ->where('t.id = :id')
            ->andWhere('t.tenantId = :tenantId')
            ->setParameter('id', Uuid::fromString($tenderId))
            ->setParameter('tenantId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $tender) {
            throw new NotFoundException('Tender not found');
        }

        return $tender;
    }

    /**
     * Правка допустима до окончания приёма заявок (FR-1.1.1, openapi PATCH).
     *
     * @throws ConflictException если статус уже не допускает правку
     */
    private function assertEditable(Tender $tender): void
    {
        if ($tender->getStatus()->phase() > TenderStatusEnum::ACCEPTING_BIDS->phase()) {
            throw new ConflictException('Tender cannot be edited after bid acceptance closes');
        }
    }

    /**
     * @throws ValidationException
     */
    private function procedureType(?string $value): ProcedureTypeEnum
    {
        $enum = null !== $value ? ProcedureTypeEnum::tryFrom($value) : null;

        return $enum ?? throw new ValidationException('procedure_type is required');
    }

    /**
     * @throws ValidationException
     */
    private function lawType(string $value): LawTypeEnum
    {
        $enum = LawTypeEnum::tryFrom($value);

        return $enum ?? throw new ValidationException('invalid law_type');
    }

    /**
     * @throws ValidationException
     */
    private function priceBasis(?string $value): PriceBasisEnum
    {
        $enum = null !== $value ? PriceBasisEnum::tryFrom($value) : null;

        return $enum ?? throw new ValidationException('price_basis is required');
    }

    /**
     * @throws ValidationException
     */
    private function accessType(string $value): AccessTypeEnum
    {
        $enum = AccessTypeEnum::tryFrom($value);

        return $enum ?? throw new ValidationException('invalid access_type');
    }

    /**
     * Разбор кода причины отмены (FR-1.1.8). Код обязателен и должен быть
     * из CancellationReasonEnum; иначе — 422.
     *
     * @throws ValidationException
     */
    private function cancellationReason(?string $value): CancellationReasonEnum
    {
        $enum = null !== $value ? CancellationReasonEnum::tryFrom($value) : null;

        return $enum ?? throw new ValidationException('cancellation_reason_code is required');
    }

    /**
     * Процент → базисные пункты (НДС). vat_rate 20 → 2000. По умолчанию 0.
     */
    private function vatBps(?float $vatRate): int
    {
        return null === $vatRate ? 0 : (int) round($vatRate * 100);
    }

    /**
     * Базисные пункты → процент (для наследования vat лота от тендера).
     */
    private function percent(int $vatRateBps): float
    {
        return $vatRateBps / 100;
    }
}
