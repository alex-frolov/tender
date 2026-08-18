<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Presenter\AuctionPresenter;
use App\Auction\Repository\AuctionRepository;
use App\Iam\Entity\User;
use App\Shared\Exception\NotFoundException;
use App\Shared\Input\InputValue;

/**
 * Список аукционов компании (FR-1.3, GET /auctions).
 *
 * Query-use-case: чтение без мутаций. Тенант — компания актора (tenant-изоляция);
 * аукционы другого тенанта не видны. Список без пагинации (по одному аукциону
 * на лот, размер ограничен). Доступ — право tenders.board.view (все роли
 * компании). Полная детализация аукциона — GET /auctions/{id}/state.
 */
final readonly class ListAuctionsUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionRepository $auctions,
        private AuctionPresenter $presenter,
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

        return [
            'items' => array_map(
                fn ($auction): array => $this->presenter->listItem($auction),
                $this->auctions->listForTenant($companyId),
            ),
        ];
    }
}
