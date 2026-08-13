<?php

declare(strict_types=1);

namespace App\Analytics\Dashboard;

use App\Auction\AuctionDashboardQuery;
use App\Contract\ContractDashboardQuery;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use App\Tender\TenderDashboardQuery;
use Symfony\Component\Uid\Uuid;

/**
 * Статистика по тендерам (AM-13, GET /stats/tenders).
 *
 * Агрегаты по срезу dimension (region/customer/period; okpd2 в модели отсутствует)
 * за период [from, to): число тендеров, средний % снижения (из итогов аукционов
 * по тендеру, PR-6) и сумма цен договоров по тендеру. Факты тендеров — из
 * публичного read-контракта Tender, снижение — из Auction, суммы — из Contract:
 * группа (по значению среза) собирается здесь, в Analytics, из фактов модулей.
 */
final readonly class TenderStatsService
{
    /** Допустимые срезы (openapi /stats/tenders dimension). */
    private const array DIMENSIONS = ['region', 'customer', 'period', 'okpd2'];

    public function __construct(
        private TenderDashboardQuery $tenders,
        private AuctionDashboardQuery $auctions,
        private ContractDashboardQuery $contracts,
    ) {
    }

    /**
     * @return list<array{dimension_value: string, tenders_total: int,
     *                    avg_price_reduction_percent: float, contracts_amount_sum_minor: int}>
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если срез не из каталога или период невалиден
     */
    public function stats(User $actor, string $dimension, ?string $from, ?string $to): array
    {
        if (!\in_array($dimension, self::DIMENSIONS, true)) {
            throw new ValidationException('invalid dimension');
        }

        $companyId = $this->requireCompany($actor);
        $fromDate = $this->parseDate($from) ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 days');
        $toDate = $this->parseDate($to) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($fromDate >= $toDate) {
            throw new ValidationException('from must be before to');
        }

        $facts = $this->tenders->factsByDimension($companyId, $dimension, $fromDate, $toDate);
        $reductions = $this->auctions->avgReductionByTender($companyId, $fromDate, $toDate);
        $amounts = $this->contracts->amountSumByTender($companyId, $fromDate, $toDate);

        $groups = [];
        foreach ($facts as $fact) {
            $value = '' === $fact['dimension_value'] ? 'unknown' : $fact['dimension_value'];
            $group = $groups[$value] ?? [
                'tenders_total' => 0,
                'reduction_sum' => 0.0,
                'reduction_count' => 0,
                'amount' => 0,
            ];
            ++$group['tenders_total'];

            $reduction = $reductions[$fact['tender_id']] ?? null;
            if (null !== $reduction) {
                $group['reduction_sum'] += $reduction;
                ++$group['reduction_count'];
            }
            $group['amount'] += $amounts[$fact['tender_id']] ?? 0;

            $groups[$value] = $group;
        }

        $items = [];
        foreach ($groups as $value => $group) {
            $items[] = [
                'dimension_value' => $value,
                'tenders_total' => $group['tenders_total'],
                'avg_price_reduction_percent' => 0 < $group['reduction_count']
                    ? round($group['reduction_sum'] / $group['reduction_count'], 2)
                    : 0.0,
                'contracts_amount_sum_minor' => $group['amount'],
            ];
        }

        // 'period' — временной ряд (по возрастанию даты); остальные — по убыванию объёма.
        usort(
            $items,
            'period' === $dimension
                ? static fn (array $a, array $b): int => $a['dimension_value'] <=> $b['dimension_value']
                : static fn (array $a, array $b): int => $b['tenders_total'] <=> $a['tenders_total']
                    ?: $a['dimension_value'] <=> $b['dimension_value'],
        );

        return $items;
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
     * Разбор даты периода (Y-m-d, UTC). null — параметр не передан (дефолт);
     * невалидный формат → ValidationException (422, открытый контракт).
     */
    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ValidationException('invalid date format, expected Y-m-d');
        }

        return new \DateTimeImmutable($value.'T00:00:00+00:00');
    }
}
