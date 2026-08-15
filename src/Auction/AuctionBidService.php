<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\BidRejectedException;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Service\BidTransaction;
use App\Auction\State\AuctionStateService;
use App\Auction\Step\BidStepCalculator;
use App\Bid\BidReadService;
use App\Infrastructure\Metrics\AuctionMetricsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Prometheus\Exception\MetricsRegistrationException;
use Symfony\Component\Uid\Uuid;

/**
 * Ставки аукциона: подача ставок REDUCTION (fixed — FR-1.3.2/1.3.3, PR-4/5;
 * free — FR-1.3.8), FREE_PRICE и PRICE_REQUEST (FR-1.3.8) и
 * первая ставка при no_start_price (FR-1.1.9, B5, AM-5).
 *
 * Сервис отвечает за ВАЛИДАЦИЮ по типу аукциона (механика шага/понижения,
 * границы, антиснайпинг-условия) и диспетчеризацию публичного `placeBid`.
 * Общий транзакционный конвейер (pessimistic lock, идемпотентность, статус
 * TRADE, rules_snapshot, допуск участника, Redis-снапшот после коммита) —
 * в приватном `transactionalBid`; тип-специфичная валидация и запись ставки —
 * в замыканиях place*.
 *
 * Последовательность (общая для всех типов):
 * - только в TRADE (FR-1.3.2; иначе 409 auction_not_trade);
 * - только допущенные участники (bids.status = admitted, FR-1.2.4);
 * - правила «заморожены» при старте (rules_snapshot, PR-9) — обязательны;
 * - валидация цены в канонической базе (PR-5/PR-6):
 *   fixed — price ≤ current − step (BidStepCalculator); free — строго ниже
 *   текущей; FREE_PRICE/PRICE_REQUEST — в границах
 *   price_min_limit_minor..price_max_limit_minor (без шага и без обязательного
 *   понижения);
 * - no_start_price (FR-1.1.9): первая ставка фиксирует start_price_minor
 *   (price discovery), становится is_first_price=true и точкой отсчёта для
 *   последующих ставок; нижняя граница (price_min_limit_minor) действует и
 *   для неё;
 * - транзакция (PostgreSQL) + Redis-снапшот live-состояния:
 *   атомарность (FR-1.3.6): ставка, current_price, аудит и outbox — в одной
 *   транзакции под pessimistic lock строки аукциона (BidTransaction); Redis-
 *   снапшот пишется ПОСЛЕ коммита (AuctionStateService), ошибка Redis не
 *   откатывает ставку;
 * - идемпотентность (ARCH-6): повторная подача с тем же Idempotency-Key
 *   (at-least-once доставка) возвращает уже принятую ставку (replay) — дубль
 *   не создаётся; DB unique (auction_id, bidder_id, round) + unique
 *   (auction_id, idempotency_key) — второй рубеж защиты от гонок.
 *
 * PRICE_REQUEST (M12, FR-1.3.8): одно ценовое предложение на участника на
 * окно торгов — round всегда 1 (unique auction+bidder+round защищает от дублей
 * на уровне БД, повторная подача того же участника отклоняется duplicate_bid);
 * после закрытия окна (planned_end_at) аукцион переходит в CHOICE, где
 * заказчик выбирает победителя (FR-1.3.5).
 */
final readonly class AuctionBidService
{
    public function __construct(
        private EntityManagerInterface $em,
        private BidStepCalculator $steps,
        private BidReadService $bids,
        private AuctionStateService $state,
        private BidTransaction $transaction,
        private AuctionMetricsCollector $auctionMetrics,
    ) {
    }

    /**
     * Подача ставки по типу аукциона (HTTP POST /auctions/{id}/bids, FR-1.3.8).
     *
     * Диспетчер контракта: тип + step_mode определяют механику ставки
     * (AuctionBidService::place*):
     * - REDUCTION(fixed) — шаг от стартовой цены (PR-4/5);
     * - REDUCTION(free) — свободное понижение ниже текущей;
     * - FREE_PRICE — свободная цена в границах price_min..price_max;
     * - PRICE_REQUEST — одно ценовое предложение на участника на окно (M12).
     *
     * @param Uuid        $bidderId       компания-участник (supplier_id)
     * @param int         $priceMinor     цена в канонической базе (PR-1/PR-6)
     * @param string|null $idempotencyKey ключ идемпотентности (AR-4)
     *
     * @throws BidRejectedException если ставка отклонена (код: auction_not_trade
     *                              | bid_rejected | duplicate_bid)
     */
    public function placeBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): AuctionBid {
        return match (true) {
            AuctionTypeEnum::REDUCTION === $auction->getType()
                && AuctionStepModeEnum::FIXED === $auction->getStepMode() => $this->placeReductionFixedBid(
                    $auction,
                    $bidderId,
                    $priceMinor,
                    $idempotencyKey,
                    $now,
                    $ip,
                ),
            AuctionTypeEnum::REDUCTION === $auction->getType()
                && AuctionStepModeEnum::FREE === $auction->getStepMode() => $this->placeReductionFreeBid(
                    $auction,
                    $bidderId,
                    $priceMinor,
                    $idempotencyKey,
                    $now,
                    $ip,
                ),
            AuctionTypeEnum::FREE_PRICE === $auction->getType() => $this->placeFreePriceBid(
                $auction,
                $bidderId,
                $priceMinor,
                $idempotencyKey,
                $now,
                $ip,
            ),
            AuctionTypeEnum::PRICE_REQUEST === $auction->getType() => $this->placePriceRequestBid(
                $auction,
                $bidderId,
                $priceMinor,
                $idempotencyKey,
                $now,
                $ip,
            ),
            default => throw new BidRejectedException('Unsupported auction type/step mode'),
        };
    }

    /**
     * Подача ставки реверсивного аукциона REDUCTION(fixed).
     *
     * Атомарность (FR-1.3.6, ARCH-6): read-modify-write (current_price →
     * валидация PR-5 → запись) выполняется под pessimistic lock строки
     * аукциона (SELECT ... FOR UPDATE) внутри транзакции. Конкурирующая ставка
     * блокируется до коммита и валидируется против обновлённой цены — гонка
     * исключена: из двух одновременных ставок с одинаковой ценой принимается
     * ровно одна. Дополнительно unique (auction_id, bidder_id, round) — защита
     * от дублей на уровне БД.
     *
     * no_start_price к REDUCTION(fixed) не применим (FR-1.1.9: только free/
     * FREE_PRICE/PRICE_REQUEST) — при отсутствии стартовой цены ставка
     * отклоняется.
     *
     * @param Uuid        $bidderId       компания-участник (supplier_id)
     * @param int         $priceMinor     цена в канонической базе (PR-1/PR-6)
     * @param string|null $idempotencyKey ключ идемпотентности (AR-4; полная
     *                                    обработка)
     *
     * @throws BidRejectedException         если ставка отклонена (код: auction_not_trade
     *                                      | bid_rejected)
     * @throws MetricsRegistrationException
     */
    public function placeReductionFixedBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): AuctionBid {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->transactionalBid($auction, $bidderId, $priceMinor, $idempotencyKey, $now, $ip, function (
            Auction $locked,
            RulesSnapshot $snapshot,
        ) use ($bidderId, $priceMinor, $idempotencyKey, $now, $ip): AuctionBid {
            if (AuctionTypeEnum::REDUCTION !== $locked->getType()
                || AuctionStepModeEnum::FIXED !== $locked->getStepMode()) {
                throw new BidRejectedException('Only REDUCTION(fixed) auctions are supported by this service');
            }

            // REDUCTION(fixed) требует известную стартовую цену: no_start_price
            // применим только к free/FREE_PRICE/PRICE_REQUEST (FR-1.1.9).
            $startMinor = $locked->getStartPriceMinor();
            if (null === $startMinor) {
                throw new BidRejectedException('REDUCTION(fixed) requires a known start price (no_start_price applies to free mode only, FR-1.1.9)');
            }

            $currentMinor = $locked->getCurrentPriceMinor() ?? $startMinor;
            $stepMinor = $this->steps->stepMinor($snapshot, $startMinor);
            $this->steps->assertValidFixedBid($priceMinor, $currentMinor, $stepMinor, $snapshot->priceMinLimitMinor);

            $round = $this->transaction->nextRound($locked);

            return $this->transaction->commitBid(
                auction: $locked,
                snapshot: $snapshot,
                bidderId: $bidderId,
                priceMinor: $priceMinor,
                round: $round,
                isFirst: false,
                idempotencyKey: $idempotencyKey,
                now: $now,
                ip: $ip,
            );
        });
    }

    /**
     * Подача ставки реверсивного аукциона REDUCTION(free) (FR-1.3.8).
     *
     * Без шага: принимается любая цена строго ниже текущей; при заданной
     * нижней границе price ≥ price_min_limit_minor (BidStepCalculator).
     * Атомарность и защита от гонок — как в placeReductionFixedBid
     * (pessimistic lock строки аукциона, FR-1.3.6/ARCH-6).
     *
     * Первая ставка при no_start_price (FR-1.1.9): фиксирует start_price_minor
     * (price discovery), помечается is_first_price=true и становится точкой
     * отсчёта для последующих ставок (они должны быть ниже её). «Ниже текущей»
     * к первой ставке неприменимо, но нижняя граница price_min_limit_minor
     * действует и для неё (иначе дальнейшие ставки были бы невозможны).
     * Зафиксированная start_price_minor — база для обеспечения заявки от первой
     * ставки (B5, FR-1.4.1; модуль securities): сумма обеспечения
     * = % × первая_ставка.
     *
     * @param Uuid        $bidderId       компания-участник (supplier_id)
     * @param int         $priceMinor     цена в канонической базе (PR-1/PR-6)
     * @param string|null $idempotencyKey ключ идемпотентности (AR-4; полная
     *                                    обработка)
     *
     * @throws BidRejectedException         если ставка отклонена (код: auction_not_trade
     *                                      | bid_rejected)
     * @throws MetricsRegistrationException
     */
    public function placeReductionFreeBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): AuctionBid {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->transactionalBid($auction, $bidderId, $priceMinor, $idempotencyKey, $now, $ip, function (
            Auction $locked,
            RulesSnapshot $snapshot,
        ) use ($bidderId, $priceMinor, $idempotencyKey, $now, $ip): AuctionBid {
            if (AuctionTypeEnum::REDUCTION !== $locked->getType()
                || AuctionStepModeEnum::FREE !== $locked->getStepMode()) {
                throw new BidRejectedException('Only REDUCTION(free) auctions are supported by this service');
            }

            // Первая ставка при no_start_price (FR-1.1.9): фиксирует стартовую
            // цену (price discovery); «ниже текущей» неприменимо, но нижняя
            // граница действует и для неё (иначе дальнейшие ставки невозможны).
            // Мутация start_price_minor — в commitBid (там же фиксируются
            // before-значения для аудита PR-9).
            $isFirst = $locked->isNoStartPrice() && null === $locked->getStartPriceMinor();
            if ($isFirst) {
                $this->steps->assertValidFirstFreeBid($priceMinor, $snapshot->priceMinLimitMinor);
            } else {
                $currentMinor = $locked->getCurrentPriceMinor() ?? $locked->getStartPriceMinor();
                if (null === $currentMinor) {
                    throw new BidRejectedException('First bid must fix the start price (FR-1.1.9)');
                }
                $this->steps->assertValidFreeBid($priceMinor, $currentMinor, $snapshot->priceMinLimitMinor);
            }

            $round = $this->transaction->nextRound($locked);

            return $this->transaction->commitBid(
                auction: $locked,
                snapshot: $snapshot,
                bidderId: $bidderId,
                priceMinor: $priceMinor,
                round: $round,
                isFirst: $isFirst,
                idempotencyKey: $idempotencyKey,
                now: $now,
                ip: $ip,
            );
        });
    }

    /**
     * Подача ставки аукциона FREE_PRICE (FR-1.3.8).
     *
     * Свободная цена: участник предлагает ЛЮБУЮ цену в заданных границах
     * price_min_limit_minor..price_max_limit_minor (обе опциональны), без шага
     * и без обязательного понижения; сравнение в канонической базе (PR-6).
     * Атомарность и защита от гонок — как в placeReductionFixedBid (pessimistic
     * lock строки аукциона, FR-1.3.6/ARCH-6).
     *
     * no_start_price (FR-1.1.9): первая ставка фиксирует start_price_minor
     * (price discovery, is_first_price=true); границы действуют и для неё.
     * current_price_minor отслеживает лучшую (минимальную) предложенную цену:
     * обязательного понижения нет, поэтому ставка выше текущей принимается,
     * но не понижает current_price.
     *
     * @param Uuid        $bidderId       компания-участник (supplier_id)
     * @param int         $priceMinor     цена в канонической базе (PR-1/PR-6)
     * @param string|null $idempotencyKey ключ идемпотентности (AR-4; полная
     *                                    обработка)
     *
     * @throws BidRejectedException         если ставка отклонена (код: auction_not_trade| bid_rejected)
     * @throws MetricsRegistrationException
     */
    public function placeFreePriceBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): AuctionBid {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->transactionalBid($auction, $bidderId, $priceMinor, $idempotencyKey, $now, $ip, function (
            Auction $locked,
            RulesSnapshot $snapshot,
        ) use ($bidderId, $priceMinor, $idempotencyKey, $now, $ip): AuctionBid {
            if (AuctionTypeEnum::FREE_PRICE !== $locked->getType()) {
                throw new BidRejectedException('Only FREE_PRICE auctions are supported by this service');
            }

            // FREE_PRICE (FR-1.3.8): любая цена в границах, без шага и без
            // обязательного понижения. Первая ставка при no_start_price
            // (FR-1.1.9) фиксирует старт (price discovery); границы действуют
            // и для неё. Мутация start_price_minor — в commitBid.
            $isFirst = $locked->isNoStartPrice() && null === $locked->getStartPriceMinor();
            $this->steps->assertValidBoundedBid(
                $priceMinor,
                $snapshot->priceMinLimitMinor,
                $snapshot->priceMaxLimitMinor,
            );

            $round = $this->transaction->nextRound($locked);

            return $this->transaction->commitBid(
                auction: $locked,
                snapshot: $snapshot,
                bidderId: $bidderId,
                priceMinor: $priceMinor,
                round: $round,
                isFirst: $isFirst,
                idempotencyKey: $idempotencyKey,
                now: $now,
                ip: $ip,
            );
        });
    }

    /**
     * Подача ценового предложения аукциона PRICE_REQUEST (M12, FR-1.3.8).
     *
     * Запрос цены: участники подают ценовые предложения в окно торгов (без
     * live-шагов). Одно предложение на участника на окно — round всегда 1
     * (unique auction+bidder+round защищает от дублей на уровне БД); повторная
     * подача того же участника отклоняется (code: duplicate_bid, FR-1.3.2).
     * Валидация — по границам price_min_limit_minor..price_max_limit_minor
     * (обе опциональны); сравнение в канонической базе (PR-6).
     *
     * Антиснайпинг (FR-1.3.3) к PRICE_REQUEST неприменим (нет live-шагов) —
     * окно закрывается по planned_end_at, после чего аукцион переходит в CHOICE
     * (FINISH, T16), где заказчик выбирает победителя (FR-1.3.5).
     *
     * no_start_price (FR-1.1.9): единственное предложение участника фиксирует
     * start_price_minor (price discovery, is_first_price=true).
     *
     * @param Uuid        $bidderId       компания-участник (supplier_id)
     * @param int         $priceMinor     цена в канонической базе (PR-1/PR-6)
     * @param string|null $idempotencyKey ключ идемпотентности (AR-4; полная
     *                                    обработка)
     *
     * @throws BidRejectedException         если предложение отклонено (код:
     * @throws MetricsRegistrationException
     *                                      auction_not_trade | bid_rejected | duplicate_bid)
     */
    public function placePriceRequestBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
        ?string $ip = null,
    ): AuctionBid {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->transactionalBid($auction, $bidderId, $priceMinor, $idempotencyKey, $now, $ip, function (
            Auction $locked,
            RulesSnapshot $snapshot,
        ) use ($bidderId, $priceMinor, $idempotencyKey, $now, $ip): AuctionBid {
            if (AuctionTypeEnum::PRICE_REQUEST !== $locked->getType()) {
                throw new BidRejectedException('Only PRICE_REQUEST auctions are supported by this service');
            }

            // PRICE_REQUEST (M12, FR-1.3.2): одно ценовое предложение на
            // участника на окно торгов. Повторная подача — duplicate_bid.
            if ($this->transaction->hasBid($locked, $bidderId)) {
                throw new BidRejectedException('One price proposal per participant per window (PRICE_REQUEST, M12)', 'duplicate_bid');
            }

            // Валидация по границам (FR-1.3.8); первая ставка при no_start_price
            // (FR-1.1.9) фиксирует старт. Мутация start_price_minor — в commitBid.
            $isFirst = $locked->isNoStartPrice() && null === $locked->getStartPriceMinor();
            $this->steps->assertValidBoundedBid(
                $priceMinor,
                $snapshot->priceMinLimitMinor,
                $snapshot->priceMaxLimitMinor,
            );

            return $this->transaction->commitBid(
                auction: $locked,
                snapshot: $snapshot,
                bidderId: $bidderId,
                priceMinor: $priceMinor,
                round: 1, // PRICE_REQUEST: без live-шагов — единственное окно (M12)
                isFirst: $isFirst,
                idempotencyKey: $idempotencyKey,
                now: $now,
                ip: $ip,
                extendOnBid: false, // без live-шагов антиснайпинг неприменим (FR-1.3.3)
            );
        });
    }

    /**
     * Общий транзакционный конвейер подачи ставки (FR-1.3.6, ARCH-6).
     *
     * Выделен из place* (декомпозиция god-сервиса):
     * pessimistic lock строки аукциона, идемпотентность (replay по
     * Idempotency-Key), проверка статуса TRADE, загрузка rules_snapshot,
     * допуск участника и Redis-снапшот live-состояния ПОСЛЕ коммита.
     * Тип-специфичную валидацию и запись ставки поставляет $validateAndCommit
     * (замыкание в place*): возвращает принятую ставку AuctionBid.
     *
     * Метрики (NFR-1/RED, ops/observability.md §1): принятая ставка —
     * bidPlaced() + bidAttempt('accepted') + bidLatency() строго после
     * коммита; отклонённая (BidRejectedException) — bidAttempt('rejected')
     * + bidRejected(причина) и проброс исключения. Replay метрики не
     * обновляет (ставка уже посчитана при первом принятии).
     *
     * @param \Closure(Auction, RulesSnapshot): AuctionBid $validateAndCommit
     *
     * @throws BidRejectedException         ставка отклонена (метрики попытки
     *                                      записаны, исключение пробрасывается)
     * @throws MetricsRegistrationException
     */
    private function transactionalBid(
        Auction $auction,
        Uuid $bidderId,
        int $priceMinor,
        ?string $idempotencyKey,
        \DateTimeImmutable $now,
        ?string $ip,
        \Closure $validateAndCommit,
    ): AuctionBid {
        // NFR-1: замер времени записи ставки (auction_bid_latency_seconds,
        // ops/observability.md §1) — весь путь: транзакция + Redis-снапшот.
        // Пишется ТОЛЬКО для принятых (не-replay) ставок: гистограмма кормит
        // SLI bid-write (slo-rules.yml, «99% ставок ≤ 100 мс») — отклонённые
        // попытки и replay не должны раздувать бакет le="0.1".
        $start = hrtime(true);
        $isReplay = false;
        try {
            $bid = $this->em->wrapInTransaction(function () use (
                $auction,
                $bidderId,
                $idempotencyKey,
                $validateAndCommit,
                &$isReplay,
            ): AuctionBid {
                $locked = $this->transaction->lockAuction($auction->getId());

                // ARCH-6: повторная доставка (at-least-once) с тем же Idempotency-Key
                // → возвращаем уже принятую ставку, дубль не создаётся (replay).
                $replay = $this->transaction->replayBid($locked, $idempotencyKey);
                if (null !== $replay) {
                    $isReplay = true;

                    return $replay;
                }

                if (!$locked->getStatus()->acceptsBids()) {
                    throw new BidRejectedException('Bids are accepted only in TRADE', 'auction_not_trade');
                }

                $rules = $locked->getRulesSnapshot();
                if (null === $rules) {
                    throw new BidRejectedException('Rules snapshot is not captured (auction not started)');
                }
                $snapshot = RulesSnapshot::fromArray($rules);

                // FR-1.3.2: ставки только от допущенных участников.
                if (!$this->bids->isAdmitted($locked->getTenderId(), $locked->getLotId(), $bidderId)) {
                    throw new BidRejectedException('Only admitted participants can place bids');
                }

                return $validateAndCommit($locked, $snapshot);
            });
        } catch (BidRejectedException $e) {
            // RED (практика Prometheus): отклонённая попытка — исход rejected и
            // причина (код исключения: bid_rejected|auction_not_trade|duplicate_bid).
            $this->auctionMetrics->bidAttempt('rejected');
            $this->auctionMetrics->bidRejected($e->getErrorCode());

            throw $e;
        }

        if ($isReplay) {
            // Replay: ставка уже была принята и посчитана ранее — метрики
            // (auction_bids_total, auction_bid_latency_seconds) НЕ обновляем.
            // Redis-снапшот освежаем (прежнее поведение).
            $this->state->write($bid->getAuction(), $bid);

            return $bid;
        }

        // Принятая и закоммиченная НОВАЯ ставка (auction_bids_total,
        // ops/observability.md §1, NFR-1). Счётчик и latency — строго ПОСЛЕ
        // коммита транзакции: откат не учитывается. Replay до этого места
        // не доходит (ветка выше) — не считается. Вызов здесь (а не в
        // commitBid) гарантирует, что метрика не сработает на ставке,
        // у которой транзакция не прошла.
        $this->auctionMetrics->bidPlaced();
        // RED (практика Prometheus): попытка с исходом — база для
        // acceptance/rejection ratio (счётчик отказов + общий счётчик попыток).
        $this->auctionMetrics->bidAttempt('accepted');

        // FR-1.3.6: Redis-снапшот live-состояния — после коммита.
        $this->state->write($bid->getAuction(), $bid);

        $this->auctionMetrics->bidLatency((hrtime(true) - $start) / 1e9);

        return $bid;
    }
}
