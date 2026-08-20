<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionVisibilityLevelEnum;
use App\Auction\Presenter\AuctionPresenter;
use App\Auction\Repository\AuctionRepository;
use App\Bid\BidWinnerQuery;
use App\Iam\Entity\User;
use App\Shared\Exception\NotFoundException;
use App\Shared\Input\InputValue;
use App\Tender\TenderReadService;
use App\Tender\TenderVisibility;
use Symfony\Component\Uid\Uuid;

/**
 * Список видимых компании аукционов (FR-1.3, FR-1.5.14, GET /auctions).
 *
 * Query-use-case: чтение без мутаций. Видимость двухступенчатая (FR-1.5.14):
 * аукцион виден, если виден его тендер (TenderVisibility) И позволяет статус
 * самого аукциона (AuctionStatusEnum::visibilityLevel) — чужому видна только
 * фаза торгов (TRADE), а стадии исполнения (APPROVE и дальше) — лишь
 * компании-исполнителю. Свои аукционы видны в любом статусе.
 *
 * Условие по статусу аукциона применяется ДО фильтра тендеров, прямо в запросе
 * кандидатов (distinctTenderIds): выборка ограничена своим тенантом, идущими
 * торгами и выигранными лотами, поэтому её стоимость определяется доступным
 * зрителю, а не общим объёмом площадки (NFR-22). Тот же набор выигранных лотов
 * переиспользуется вторым шагом. Оба шага — по одному запросу на весь список,
 * проверок построчно (N+1) нет.
 * Список без пагинации (по одному аукциону на лот). Доступ — право
 * tenders.board.view (все роли компании). Полная детализация аукциона —
 * GET /auctions/{id}/state.
 *
 * Строки обогащаются номером/названием тендера и лота (AuctionListItem.
 * tender_title/lot_title): иначе в UI видны только UUID. Подписи берутся одним
 * запросом через публичный контракт TenderReadService::lotLabels (границы
 * модулей: сущности Tender/Lot сюда не попадают).
 *
 * Туда же добавляется последняя принятая ставка (last_bid_at,
 * last_bid_price_minor) — одной выборкой на всю страницу
 * (AuctionRepository::lastAcceptedBids), без N+1 по коллекции ставок.
 */
final readonly class ListAuctionsUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionRepository $auctions,
        private AuctionPresenter $presenter,
        private TenderReadService $tenders,
        private TenderVisibility $visibility,
        private BidWinnerQuery $winners,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     *
     * @throws NotFoundException если у актора нет компании
     */
    public function execute(User $user): array
    {
        $companyId = InputValue::companyId($user);

        // Лоты, выигранные компанией: один запрос на оба шага видимости.
        $wonLotIds = $this->winners->lotIdsWonBy($companyId);

        $visibleTenderIds = $this->visibility->filterVisible(
            $this->auctions->distinctTenderIds(
                $companyId,
                AuctionStatusEnum::valuesWithVisibility(AuctionVisibilityLevelEnum::TENDER_VIEWERS),
                $wonLotIds,
            ),
            $companyId,
        );
        $auctions = $this->auctions->listForTenders($visibleTenderIds);
        $auctions = $this->filterByAuctionStatus($auctions, $companyId, $wonLotIds);

        $labels = $this->tenders->lotLabels(array_map(
            static fn (Auction $auction): string => (string) $auction->getLotId(),
            $auctions,
        ));

        $lastBids = $this->auctions->lastAcceptedBids(array_map(
            static fn (Auction $auction): Uuid => $auction->getId(),
            $auctions,
        ));

        return [
            'items' => array_map(
                fn (Auction $auction): array => $this->presenter->listItem(
                    $auction,
                    $labels[(string) $auction->getLotId()] ?? null,
                    $lastBids[(string) $auction->getId()] ?? null,
                ),
                $auctions,
            ),
        ];
    }

    /**
     * Второй шаг видимости: статус самого аукциона (FR-1.5.14). Запрос
     * кандидатов уже отсёк лишнее на стороне БД, здесь правило проверяется
     * на загруженных строках — единственным источником истины остаётся
     * AuctionStatusEnum::visibilityLevel.
     *
     * @param list<Auction> $auctions
     * @param list<Uuid>    $wonLotIds лоты, выигранные компанией-зрителем
     *
     * @return list<Auction>
     */
    private function filterByAuctionStatus(array $auctions, Uuid $companyId, array $wonLotIds): array
    {
        // Строковые id как ключи — проверка членства O(1) на строку.
        $wonLots = [];
        foreach ($wonLotIds as $lotId) {
            $wonLots[(string) $lotId] = true;
        }

        return array_values(array_filter($auctions, static function (Auction $auction) use ($companyId, $wonLots): bool {
            if ($auction->getTenantId()->equals($companyId)) {
                return true;
            }

            return match ($auction->getStatus()->visibilityLevel()) {
                AuctionVisibilityLevelEnum::OWNER_ONLY => false,
                AuctionVisibilityLevelEnum::TENDER_VIEWERS => true,
                AuctionVisibilityLevelEnum::OWNER_AND_WINNER => isset($wonLots[(string) $auction->getLotId()]),
            };
        }));
    }
}
