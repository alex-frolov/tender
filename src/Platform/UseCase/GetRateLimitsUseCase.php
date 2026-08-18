<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Текущие лимиты API (GET /rate-limits, FR-1.5.15).
 *
 * «Peek» через consume(0) — не расходует токены (token_bucket: при
 * tokens==0 сохранение не выполняется; sliding_window: ранний выход без
 * мутации окна). Ключ — компания актора (общий лимит по IP/ключу —
 * глобальный, для остальных — по тенанту). Возвращает лимит, остаток
 * и время сброса.
 */
final readonly class GetRateLimitsUseCase implements PlatformUseCase
{
    public function __construct(
        #[Autowire(service: 'limiter.api_global')]
        private RateLimiterFactory $apiGlobal,
        #[Autowire(service: 'limiter.auction_bids')]
        private RateLimiterFactory $auctionBids,
        #[Autowire(service: 'limiter.tender_reads')]
        private RateLimiterFactory $tenderReads,
    ) {
    }

    /**
     * @return array{
     *     global: array{limit: int, remaining: int, reset_at: string},
     *     per_tender: array<string, array{limit: int, remaining: int, reset_at: string}>
     * }
     */
    public function execute(User $user): array
    {
        $key = null !== $user->getCompanyId() ? (string) $user->getCompanyId() : (string) $user->getId();

        return [
            'global' => $this->snapshot($this->apiGlobal->create($key)),
            'per_tender' => [
                'auction_bids' => $this->snapshot($this->auctionBids->create($key)),
                'tender_reads' => $this->snapshot($this->tenderReads->create($key)),
            ],
        ];
    }

    /**
     * @return array{limit: int, remaining: int, reset_at: string}
     */
    private function snapshot(\Symfony\Component\RateLimiter\LimiterInterface $limiter): array
    {
        $hit = $limiter->consume(0);

        return [
            'limit' => $hit->getLimit(),
            'remaining' => $hit->getRemainingTokens(),
            'reset_at' => $hit->getRetryAfter()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
