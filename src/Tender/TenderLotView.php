<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderVisibilityLevelEnum;
use App\Tender\Entity\Lot;
use Symfony\Component\Uid\Uuid;

/**
 * Что зритель видит ВНУТРИ уже видимого ему тендера (FR-1.5.14).
 *
 * Видимость тендера (TenderVisibility) отвечает только на вопрос «показывать
 * ли закупку вообще». Состав карточки — второй, независимый вопрос: закупка
 * публична всю активную часть процедуры, но наружу из неё уходят лишь
 * публичные данные. Здесь живут два правила состава:
 *   - какие лоты попадают в карточку (LotStatusEnum::visibilityLevel):
 *     завершённый или отменённый лот виден заказчику и исполнителю этого лота,
 *     остальным — нет, хотя сам тендер им виден (статус тендера — «бутылочное
 *     горлышко» лотов, FR-1.1.3, поэтому закрытый лот легко живёт внутри
 *     активной закупки);
 *   - раскрывается ли победитель лота (Lot::winnerBidId): личность исполнителя
 *     — не рыночная информация, посторонний её не получает даже на стадиях
 *     awarding/contract, когда сама закупка ему видна.
 *
 * Заказчик (тенант тендера) видит всё — TenderLotView::owner(). Для прочих
 * зрителей объект собирает TenderVisibilityService::lotViewOf(), подставляя
 * лоты, выигранные компанией зрителя (App\Bid\BidWinnerQuery::lotIdsWonBy).
 * Потребитель — TenderPresenter (карточка тендера и список лотов).
 */
final readonly class TenderLotView
{
    /**
     * @param bool                      $isOwner     зритель — заказчик тендера (видит всё)
     * @param TenderVisibilityLevelEnum $tenderLevel круг видимости самого тендера
     * @param array<string, true>       $wonLotIds   лоты зрителя-исполнителя, строковый id → true
     */
    private function __construct(
        public bool $isOwner,
        private TenderVisibilityLevelEnum $tenderLevel,
        private array $wonLotIds,
    ) {
    }

    /**
     * Взгляд заказчика (и любой внутренний вызов — ответ на собственную
     * мутацию): ограничений состава нет.
     */
    public static function owner(): self
    {
        return new self(true, TenderVisibilityLevelEnum::OWNER_ONLY, []);
    }

    /**
     * Взгляд стороннего зрителя: ограничен матрицей статусов лота и списком
     * лотов, где его компания — исполнитель.
     *
     * @param list<Uuid> $wonLotIds лоты, выигранные компанией зрителя
     */
    public static function outsider(TenderStatusEnum $tenderStatus, array $wonLotIds): self
    {
        $index = [];
        foreach ($wonLotIds as $lotId) {
            // Строковые id как ключи — проверка членства O(1) на лот.
            $index[(string) $lotId] = true;
        }

        return new self(false, $tenderStatus->visibilityLevel(), $index);
    }

    /**
     * Попадает ли лот в выдачу этому зрителю.
     */
    public function includes(Lot $lot): bool
    {
        if ($this->isOwner) {
            return true;
        }

        return match ($this->levelOf($lot)) {
            TenderVisibilityLevelEnum::OWNER_ONLY => false,
            TenderVisibilityLevelEnum::PARTICIPANTS => true,
            TenderVisibilityLevelEnum::OWNER_AND_WINNER => $this->isWinner($lot),
        };
    }

    /**
     * Раскрывать ли победителя лота (winner_bid_id). Заказчику — всегда,
     * исполнителю — по своему лоту, прочим — никогда: по id заявки победителя
     * его компания вычисляется из списка вскрытых заявок тендера
     * (GET /tenders/{id}/bids отдаёт supplier_id), поэтому маскируется именно
     * ссылка на заявку, а не только имя компании.
     */
    public function revealsWinner(Lot $lot): bool
    {
        return $this->isOwner || $this->isWinner($lot);
    }

    /**
     * Круг видимости конкретного лота.
     *
     * Черновой лот наследует круг видимости своего тендера. Это не про обычный
     * ход процедуры — фазы лота ведутся тендером и аукционом
     * (tender-state-machine.md, раздел 3a) — а про край: лот черновика ещё не
     * опубликованного тендера либо лот, добавленный между публикацией и
     * догоном фазы. Своего круга у такого лота нет, и решает за него тендер.
     *
     * Лот, который до статуса уже дошёл (closed/cancelled), решает за себя и
     * может сузить видимость тендера, но не расширить её — тендер к этому
     * моменту зрителю уже виден.
     */
    private function levelOf(Lot $lot): TenderVisibilityLevelEnum
    {
        $status = $lot->getStatus();

        return LotStatusEnum::DRAFT === $status
            ? $this->tenderLevel
            : $status->visibilityLevel();
    }

    private function isWinner(Lot $lot): bool
    {
        return isset($this->wonLotIds[(string) $lot->getId()]);
    }
}
