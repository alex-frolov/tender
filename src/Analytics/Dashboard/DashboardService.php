<?php

declare(strict_types=1);

namespace App\Analytics\Dashboard;

use App\Auction\AuctionDashboardQuery;
use App\Bid\BidDashboardQuery;
use App\Contract\ContractDashboardQuery;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Tender\TenderDashboardQuery;
use Symfony\Component\Uid\Uuid;

/**
 * Дашборд компании (AM-13, GET /dashboard).
 *
 * Композиция счётчиков и ближайших дедлайнов из публичных read-контрактов
 * модулей-владельцев (Tender/Bid/Auction/Contract): активные тендеры (по
 * агрегированному статусу FR-1.1.3), мои заявки (как поставщик), мои
 * договоры (как сторона), ближайшие дедлайны приёма заявок и окончания торгов.
 *
 * Срез данных — «процедуры компании» в обе стороны: свои (компания —
 * заказчик, tenant-изоляция на уровне запросов модулей) И те, где компания
 * участвует — есть заявка (BidDashboardQuery) или ставка на аукционе
 * (AuctionDashboardQuery; в тендере с bids_required=false заявки нет вовсе).
 * Раньше учитывался только тенант, и у исполнителя дашборд был пуст:
 * «активные тендеры» 0 и ни одного ближайшего срока, сколько бы он ни торговался.
 *
 * Актор без компании (platform_admin) не имеет дашборда компании → 409.
 *
 * period (day/week/month) ограничивает горизонт upcoming_deadlines
 * (ближайшие 1 день / 7 дней / 30 дней); счётчики — снапшот-мгновенные.
 */
final readonly class DashboardService
{
    /** Максимум дедлайнов в ответе. */
    private const int DEADLINE_LIMIT = 10;

    /** Горизонты дедлайнов по period (day/week/month) → смещение от now. */
    private const array PERIOD_HORIZONS = [
        'day' => 'P1D',
        'week' => 'P7D',
        'month' => 'P30D',
    ];

    public function __construct(
        private TenderDashboardQuery $tenders,
        private BidDashboardQuery $bids,
        private AuctionDashboardQuery $auctions,
        private ContractDashboardQuery $contracts,
    ) {
    }

    /**
     * @param string|null $period горизонт дедлайнов (day/week/month) или null без ограничения
     *
     * @return array{active_tenders: int, my_bids: int, my_contracts: int,
     *              upcoming_deadlines: list<array{entity_type: string, entity_id: string, deadline_at: string}>}
     *
     * @throws ConflictException если актор без компании
     */
    public function get(User $actor, ?string $period = null): array
    {
        $companyId = $this->requireCompany($actor);
        $until = $this->horizon($period);
        $participating = $this->participatingTenderIds($companyId);

        $deadlines = [];
        foreach ($this->tenders->upcomingBidDeadlines($companyId, self::DEADLINE_LIMIT, $until, $participating) as $row) {
            $deadlines[] = [
                'entity_type' => 'tender',
                'entity_id' => $row['tender_id'],
                'deadline_at' => $row['deadline_at'],
            ];
        }
        foreach ($this->auctions->upcomingTradeEnds($companyId, self::DEADLINE_LIMIT, $until, $participating) as $row) {
            $deadlines[] = [
                'entity_type' => 'auction',
                'entity_id' => $row['auction_id'],
                'deadline_at' => $row['deadline_at'],
            ];
        }

        usort(
            $deadlines,
            static fn (array $a, array $b): int => $a['deadline_at'] <=> $b['deadline_at'],
        );

        return [
            'active_tenders' => $this->tenders->countActive($companyId, $participating),
            'my_bids' => $this->bids->countForSupplier($companyId),
            'my_contracts' => $this->contracts->countForCompany($companyId),
            'upcoming_deadlines' => \array_slice($deadlines, 0, self::DEADLINE_LIMIT),
        ];
    }

    /**
     * Процедуры, где компания участвует, но не является заказчиком: тендеры
     * её заявок плюс тендеры аукционов, где она ставила ставки (в тендере без
     * заявки на участие первый источник пуст). Дубликаты убираются —
     * дальше список уходит в `IN (...)`.
     *
     * @return list<Uuid>
     */
    private function participatingTenderIds(Uuid $companyId): array
    {
        $ids = [];
        foreach ([...$this->bids->tenderIdsForSupplier($companyId), ...$this->auctions->tenderIdsForBidder($companyId)] as $id) {
            $ids[(string) $id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * Верхняя граница горизонта дедлайнов по period (day/week/month):
     * now + смещение. Неизвестный/отсутствующий period → null (без ограничения);
     * допустимость значений гарантирует ChoiceType формы DashboardQueryType.
     */
    private function horizon(?string $period): ?\DateTimeImmutable
    {
        $interval = self::PERIOD_HORIZONS[$period ?? ''] ?? null;
        if (null === $interval) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval($interval));
    }
}
