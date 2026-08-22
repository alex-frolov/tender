<?php

declare(strict_types=1);

namespace App\Auction\Service;

use App\Auction\Entity\Auction;
use App\Tender\LotWriteService;
use App\Tender\TenderStatusAggregator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Проведение лота по фазам вслед за аукционом (FR-1.1.3/1.1.7, FR-1.3.7).
 *
 * Реальный процесс закупки идёт на уровне лота, а ведёт его аукцион: вышли
 * торги — лот в bidding, утверждён победитель — awarding, принято исполнение —
 * closed. По фазам лотов затем агрегируется статус тендера (вариант C
 * «бутылочное горлышко», domain/tender-state-machine.md раздел 3).
 *
 * Почему слушатель, а не вызовы на местах: переходы аукциона применяются из
 * восьми мест модуля (AuctionService, AuctionWinnerService, AuctionWriteService,
 * WinnerTransaction, AuctionLifecycleService), в том числе под пессимистичной
 * блокировкой. Подписка на workflow.auction.entered гарантирует, что ни один
 * переход — ни существующий, ни будущий — не забудет про лот.
 *
 * Flush здесь не выполняется (`flush: false` в обоих вызовах): изменения лота
 * и тендера сохраняются вместе с транзакцией вызывающего. Иначе переход
 * аукциона порождал бы отдельный коммит посреди чужой транзакции — для торгов
 * под блокировкой это недопустимо.
 *
 * Границы модулей: лот мутируется через публичный контракт
 * App\Tender\LotWriteService, статус тендера — через App\Tender\TenderStatusAggregator;
 * сущности модуля Tender сюда не попадают.
 */
#[AsEventListener(event: 'workflow.auction.entered')]
final readonly class AuctionLotPhaseListener
{
    public function __construct(
        private LotWriteService $lots,
        private TenderStatusAggregator $tenders,
    ) {
    }

    /**
     * @param EnteredEvent<Auction> $event
     */
    public function __invoke(EnteredEvent $event): void
    {
        $auction = $event->getSubject();
        if (!$auction instanceof Auction) {
            return;
        }

        // Подготовка торгов и мягкое удаление фазу лота не трогают.
        $transition = $auction->getStatus()->lotTransition();
        if (null === $transition) {
            return;
        }

        $this->lots->applyTransition($auction->getLotId(), $transition, flush: false);
        $this->tenders->recalculateById($auction->getTenderId(), flush: false);
    }
}
