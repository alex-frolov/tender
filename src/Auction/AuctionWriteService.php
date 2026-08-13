<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\AuctionNotFoundException;
use App\Auction\Input\CreateAuctionInput;
use App\Auction\Input\ScheduleAuctionInput;
use App\Auction\Input\UpdateAuctionInput;
use App\Auction\Repository\AuctionRepository;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Создание и управление аукционом до торгов (FR-1.3, POST /auctions,
 * PATCH /auctions/{id}, /auctions/{id}/schedule, /auctions/{id}/cancel).
 *
 * - create(): аукцион создаётся из лота (производный 1:1, unique tender+lot).
 *   Канонические параметры наследуются от лота (PR-6): price_basis, vat_rate_bps,
 *   стартовая цена (canonical price лота, если не no_start_price), trade_end_lead_hours;
 *   параметры торгов (тип/шаг/лимиты/длительность) — из тела. Статус — new
 *   (конструктор, initial_marking); переход в SCHEDULED — только через workflow
 *   (T10), поэтому при наличии scheduled_start_at аукцион сначала создаётся,
 *   затем планируется этим же сервисом (schedule()).
 * - update(): PATCH — правка параметров торгов (тип/шаг/лимиты/длительность/
 *   продления) пока торги не начались (статус draft/agreement/new/scheduled);
 *   канонические поля из лота не редактируются. Статус не меняется.
 * - schedule(): NEW → SCHEDULED (T10), фиксирует scheduled_start_at (будущее);
 * - cancel(): → CANCELLED из любого допускающего отмену статуса (T7/T9/T12/T14/
 *   T19/T22/T25/T28/T32) через workflow.
 *
 * Статусы меняются ТОЛЬКО через symfony/workflow (AGENTS.md). Tenant-изоляция:
 * актор (заказчик) должен быть компанией-тенантом тендера/аукциона, иначе — 404.
 * Аудит (FR-1.8) + outbox-события auction.created/scheduled/cancelled/updated
 * (domain/events.md) пишутся в одной транзакции с изменением.
 */
final readonly class AuctionWriteService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private AuctionRepository $auctions,
        private TenderReadService $tenders,
        #[Autowire(service: 'state_machine.auction')]
        private WorkflowInterface $auctionWorkflow,
    ) {
    }

    /**
     * Создание аукциона по лоту (POST /auctions).
     *
     * @throws ConflictException        если актор без компании или аукцион на лот уже есть
     * @throws AuctionNotFoundException если лот не найден или тендер не принадлежит компании
     * @throws ValidationException      если параметры аукциона невалидны
     */
    public function create(User $actor, CreateAuctionInput $input, ?string $ip = null): Auction
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $lot = $this->tenders->resolveLotById((string) $input->lotId);
        $tenderId = $lot->getTender()->getId();
        if (!$this->tenders->belongsToCompany($tenderId, $companyId)) {
            throw new AuctionNotFoundException();
        }
        $tender = $this->tenders->resolveTender((string) $tenderId);

        if (null !== $this->auctions->findForLot($tenderId, $lot->getId())) {
            throw new ConflictException('Auction already exists for the lot');
        }

        $type = $this->type($input->type);
        $stepMode = $this->stepMode($input->stepMode);
        $noStartPrice = $tender->isNoStartPrice();
        $startPriceMinor = $noStartPrice ? null : $lot->getCanonicalPriceMinor();

        $this->assertValidParams(
            $type,
            $stepMode,
            $input->bidStepMinor,
            $input->bidStepPercentBps,
            $input->priceMinLimitMinor,
            $input->priceMaxLimitMinor,
            $startPriceMinor,
        );

        $auction = new Auction(
            tenderId: $tenderId,
            lotId: $lot->getId(),
            tenantId: $companyId,
            type: $type,
            stepMode: $stepMode,
            noStartPrice: $noStartPrice,
            bidStepMinor: $input->bidStepMinor,
            bidStepPercentBps: $input->bidStepPercentBps,
            priceMinLimitMinor: $input->priceMinLimitMinor,
            priceMaxLimitMinor: $input->priceMaxLimitMinor,
            stepDurationSec: $input->stepDurationSec ?? 600,
            maxExtensions: $input->maxExtensions ?? 10,
            startPriceMinor: $startPriceMinor,
            tradeEndLeadHours: $lot->getTradeEndLeadHours(),
            priceBasis: $lot->getPriceBasis(),
            vatRateBps: $lot->getVatRateBps(),
        );

        $this->em->persist($auction);
        $this->audit->record(
            action: 'auction.created',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'tender_id' => (string) $tenderId,
                'lot_id' => (string) $lot->getId(),
                'type' => $type->value,
                'step_mode' => $stepMode->value,
                'no_start_price' => $noStartPrice,
                'start_price_minor' => $startPriceMinor,
                'status' => $auction->getStatus()->value,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.created',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'tender_id' => (string) $tenderId,
                'lot_id' => (string) $lot->getId(),
                'type' => $type->value,
                'status' => $auction->getStatus()->value,
                'no_start_price' => $noStartPrice,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $companyId,
        ));
        $this->em->flush();

        // Планирование при создании (T10): статус меняется только через workflow.
        if (null !== $input->scheduledStartAt && '' !== trim($input->scheduledStartAt)) {
            $scheduleInput = new ScheduleAuctionInput();
            $scheduleInput->scheduledStartAt = $input->scheduledStartAt;
            $this->schedule($auction, $actor, $scheduleInput, $ip);
        }

        return $auction;
    }

    /**
     * Правка параметров торгов до старта (PATCH /auctions/{id}, FR-1.3.1).
     *
     * PATCH-семантика: меняются только переданные поля (null = не менять).
     * Редактируемы: тип/step_mode/шаг/лимиты/длительность/продления. Канонические
     * поля из лота (price_basis/vat_rate_bps/start_price_minor/trade_end_lead_hours)
     * и scheduled_start_at этим методом не меняются. Доменные правила валидируются
     * по результирующему состоянию. Статус не меняется (правка не является
     * переходом workflow); правила замораживаются только при входе в TRADE (PR-9).
     *
     * @throws AuctionNotFoundException если актор не тенант аукциона
     * @throws StateTransitionException если торги уже начались (TRADE и далее)
     * @throws ValidationException      если нечего менять или параметры невалидны
     */
    public function update(Auction $auction, User $actor, UpdateAuctionInput $input, ?string $ip = null): Auction
    {
        $this->assertCustomer($auction, $actor);
        $this->assertEditable($auction);

        $type = null !== $input->type ? $this->type($input->type) : $auction->getType();
        $stepMode = null !== $input->stepMode ? $this->stepMode($input->stepMode) : $auction->getStepMode();

        $changed = [];
        if ($auction->getType() !== $type) {
            $auction->setType($type);
            $changed[] = 'type';
        }
        if ($auction->getStepMode() !== $stepMode) {
            $auction->setStepMode($stepMode);
            $changed[] = 'step_mode';
        }
        // NOT_SET — поле не передано (не менять); null — явный сброс; int — новое значение.
        if (UpdateAuctionInput::NOT_SET !== $input->bidStepMinor && $auction->getBidStepMinor() !== $input->bidStepMinor) {
            $auction->setBidStepMinor($input->bidStepMinor);
            $changed[] = 'bid_step_minor';
        }
        if (UpdateAuctionInput::NOT_SET !== $input->bidStepPercentBps && $auction->getBidStepPercentBps() !== $input->bidStepPercentBps) {
            $auction->setBidStepPercentBps($input->bidStepPercentBps);
            $changed[] = 'bid_step_percent_bps';
        }
        if (UpdateAuctionInput::NOT_SET !== $input->priceMinLimitMinor && $auction->getPriceMinLimitMinor() !== $input->priceMinLimitMinor) {
            $auction->setPriceMinLimitMinor($input->priceMinLimitMinor);
            $changed[] = 'price_min_limit_minor';
        }
        if (UpdateAuctionInput::NOT_SET !== $input->priceMaxLimitMinor && $auction->getPriceMaxLimitMinor() !== $input->priceMaxLimitMinor) {
            $auction->setPriceMaxLimitMinor($input->priceMaxLimitMinor);
            $changed[] = 'price_max_limit_minor';
        }
        if (null !== $input->stepDurationSec && $auction->getStepDurationSec() !== $input->stepDurationSec) {
            $auction->setStepDurationSec($input->stepDurationSec);
            $changed[] = 'step_duration_sec';
        }
        if (null !== $input->maxExtensions && $auction->getMaxExtensions() !== $input->maxExtensions) {
            $auction->setMaxExtensions($input->maxExtensions);
            $changed[] = 'max_extensions';
        }

        if ([] === $changed) {
            throw new ValidationException('nothing to update');
        }

        // Доменные правила применяются к результирующему состоянию аукциона.
        $this->assertValidParams(
            $auction->getType(),
            $auction->getStepMode(),
            $auction->getBidStepMinor(),
            $auction->getBidStepPercentBps(),
            $auction->getPriceMinLimitMinor(),
            $auction->getPriceMaxLimitMinor(),
            $auction->getStartPriceMinor(),
        );

        $this->audit->record(
            action: 'auction.updated',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'status' => $auction->getStatus()->value,
                'changed_fields' => $changed,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.updated',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'changed_fields' => $changed,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        return $auction;
    }

    /**
     * Планирование старта торгов (T10, NEW → SCHEDULED, POST /auctions/{id}/schedule).
     * scheduled_start_at обязан быть в будущем; событие auction.scheduled
     * (auction_id, scheduled_start_at, planned_end_at — таймер стартует позже).
     *
     * @throws AuctionNotFoundException если актор не тенант аукциона
     * @throws ValidationException      если дата не указана/в прошлом/невалидна
     * @throws StateTransitionException если аукцион не в NEW
     */
    public function schedule(Auction $auction, User $actor, ScheduleAuctionInput $input, ?string $ip = null): Auction
    {
        $this->assertCustomer($auction, $actor);

        $date = $this->scheduledDate($input->scheduledStartAt);
        $auction->setScheduledStartAt($date);
        $this->applyTransition($auction, AuctionStatusTransition::SCHEDULE);

        $this->audit->record(
            action: 'auction.scheduled',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'status' => $auction->getStatus()->value,
                'scheduled_start_at' => $date->format('Y-m-d\TH:i:s\Z'),
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.scheduled',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'scheduled_start_at' => $date->format('Y-m-d\TH:i:s\Z'),
                'planned_end_at' => null,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        return $auction;
    }

    /**
     * Отмена аукциона (→ CANCELLED, POST /auctions/{id}/cancel). Причина —
     * свободный текст (в аудит и событие auction.cancelled). Статус меняется
     * только через workflow (допустимые исходные статусы из T7/T9/T12/T14/
     * T19/T22/T25/T28/T32).
     *
     * @throws AuctionNotFoundException если актор не тенант аукциона
     * @throws StateTransitionException если отмена из текущего статуса недопустима
     */
    public function cancel(Auction $auction, User $actor, ?string $reason, ?string $ip = null): Auction
    {
        $this->assertCustomer($auction, $actor);

        $reason = null !== $reason ? trim($reason) : null;
        $this->applyTransition($auction, AuctionStatusTransition::CANCEL);

        $this->audit->record(
            action: 'auction.cancelled',
            entityType: 'auction',
            entityId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'status' => $auction->getStatus()->value,
                'reason' => $reason,
            ],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'auction.cancelled',
            payload: [
                'auction_id' => (string) $auction->getId(),
                'reason' => $reason,
            ],
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            tenantId: (string) $auction->getTenantId(),
        ));
        $this->em->flush();

        return $auction;
    }

    /**
     * Tenant-изоляция (AGENTS.md): управление аукционом выполняет только
     * заказчик — компания-тенант аукциона. Чужой актор → 404.
     */
    private function assertCustomer(Auction $auction, User $actor): void
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId || !$auction->getTenantId()->equals($companyId)) {
            throw new AuctionNotFoundException();
        }
    }

    /**
     * Правка допустима до старта торгов (FR-1.3.1): статус DRAFT/AGREEMENT/NEW/
     * SCHEDULED. После входа в TRADE правила заморожены (rules_snapshot, PR-9),
     * изменение параметров торгов недопустимо.
     *
     * @throws StateTransitionException если торги уже идут/завершены
     */
    private function assertEditable(Auction $auction): void
    {
        if (!\in_array($auction->getStatus(), [
            AuctionStatusEnum::DRAFT,
            AuctionStatusEnum::AGREEMENT,
            AuctionStatusEnum::NEW,
            AuctionStatusEnum::SCHEDULED,
        ], true)) {
            throw new StateTransitionException('Auction can be updated only before trading starts (draft/agreement/new/scheduled)');
        }
    }

    /**
     * Применение перехода state_machine.auction (can → apply → flush).
     *
     * @throws StateTransitionException если переход из текущего статуса недопустим
     */
    private function applyTransition(Auction $auction, AuctionStatusTransition $transition): void
    {
        $name = $transition->value;
        if (!$this->auctionWorkflow->can($auction, $name)) {
            throw new StateTransitionException(\sprintf('Auction cannot apply transition %s from status %s', $name, $auction->getStatus()->value));
        }

        $this->auctionWorkflow->apply($auction, $name);
        $this->em->flush();
    }

    /**
     * Доменная валидация параметров аукциона (дополнительно к форме):
     * шаг обязателен для REDUCTION+fixed (PR-4), консистентность границ цен.
     * Применяется к результирующему состоянию (create и update).
     *
     * @throws ValidationException
     */
    private function assertValidParams(
        AuctionTypeEnum $type,
        AuctionStepModeEnum $stepMode,
        ?int $bidStepMinor,
        ?int $bidStepPercentBps,
        ?int $priceMinLimitMinor,
        ?int $priceMaxLimitMinor,
        ?int $startPriceMinor,
    ): void {
        if (AuctionTypeEnum::REDUCTION === $type
            && AuctionStepModeEnum::FIXED === $stepMode
            && null === $bidStepMinor
            && null === $bidStepPercentBps
        ) {
            throw new ValidationException('bid step is required for reduction auction with fixed step (bid_step_minor or bid_step_percent_bps)');
        }

        if (null !== $priceMinLimitMinor
            && null !== $priceMaxLimitMinor
            && $priceMinLimitMinor > $priceMaxLimitMinor
        ) {
            throw new ValidationException('price_min_limit_minor must be <= price_max_limit_minor');
        }

        // Для REDUCTION ставки идут вниз от стартовой цены: нижняя граница не
        // может превышать старт.
        if (AuctionTypeEnum::REDUCTION === $type
            && null !== $startPriceMinor
            && null !== $priceMinLimitMinor
            && $priceMinLimitMinor > $startPriceMinor
        ) {
            throw new ValidationException('price_min_limit_minor must not exceed the start price');
        }
    }

    /**
     * @throws ValidationException
     */
    private function type(?string $value): AuctionTypeEnum
    {
        $enum = null !== $value ? AuctionTypeEnum::tryFrom($value) : null;

        return $enum ?? throw new ValidationException('type is required');
    }

    private function stepMode(?string $value): AuctionStepModeEnum
    {
        if (null === $value || '' === $value) {
            return AuctionStepModeEnum::FIXED;
        }

        $enum = AuctionStepModeEnum::tryFrom($value);

        return $enum ?? throw new ValidationException('invalid step_mode');
    }

    /**
     * Разбор даты старта: обязательна, валидный ISO-формат, в будущем.
     *
     * @throws ValidationException
     */
    private function scheduledDate(?string $value): \DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            throw new ValidationException('scheduled_start_at is required');
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new ValidationException('scheduled_start_at must be a valid date-time');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($date->getTimestamp() <= $now->getTimestamp()) {
            throw new ValidationException('scheduled_start_at must be in the future');
        }

        return $date;
    }
}
