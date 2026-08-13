<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender\Repository;

use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Repository\TenderRepository;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Read-агрегация статуса тендера при мультилоте (FR-1.1.3, вариант C).
 *
 * TenderRepository::aggregatedStatuses() (DB: STRING_AGG статусов лотов) должна
 * давать тот же результат, что и Tender::aggregatedStatus() (по объектам) — единый
 * источник истины Tender::aggregateStatus(). Также проверяется, что listForTenant()
 * eager-грузит лоты (нет N+1: lotCount/aggregatedStatus не делают доп. запросов).
 */
final class TenderRepositoryTest extends KernelTestCase
{
    private TenderRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(TenderRepository::class);
        if (!$repo instanceof TenderRepository) {
            throw new \LogicException('TenderRepository not resolvable');
        }
        $this->repository = $repo;
    }

    public function testAggregatedStatusesMatchEntityAggregation(): void
    {
        $tenant = Uuid::v4();

        // 1) отстающий лот: bidding при second accepting_bids → accepting_bids
        $lagging = TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => 1000]);
        $lots = [
            LotFactory::createOne(['tender' => $lagging, 'priceNetMinor' => 500, 'number' => 1]),
            LotFactory::createOne(['tender' => $lagging, 'priceNetMinor' => 500, 'number' => 2]),
        ];
        $lots[0]->setStatus(LotStatusEnum::BIDDING);
        $lots[1]->setStatus(LotStatusEnum::ACCEPTING_BIDS);

        // 2) все closed → closed
        $allClosed = TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => 1000]);
        $closedLots = [
            LotFactory::createOne(['tender' => $allClosed, 'priceNetMinor' => 500, 'number' => 1]),
            LotFactory::createOne(['tender' => $allClosed, 'priceNetMinor' => 500, 'number' => 2]),
        ];
        $closedLots[0]->setStatus(LotStatusEnum::CLOSED);
        $closedLots[1]->setStatus(LotStatusEnum::CLOSED);

        // 3) без лотов → административный статус (draft)
        TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => null, 'noStartPrice' => true]);

        $aggregated = $this->repository->aggregatedStatuses($tenant);

        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $aggregated[(string) $lagging->getId()]);
        self::assertSame(TenderStatusEnum::CLOSED, $aggregated[(string) $allClosed->getId()]);
    }

    public function testListForTenantEagerLoadsLots(): void
    {
        $tenant = Uuid::v4();
        $tender = TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => 1000]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);
        $lot->setStatus(LotStatusEnum::BIDDING);

        // listForTenant должен вернуть тендер; агрегированный статус доступен без
        // дополнительного запроса (ленивая загрузка не сработает после getResult).
        $result = $this->repository->listForTenant($tenant);
        self::assertCount(1, $result);

        $loaded = $result[0];
        self::assertSame(TenderStatusEnum::BIDDING, $loaded->aggregatedStatus());
        self::assertSame(1, $loaded->lotCount());
    }

    public function testFactsByDimensionCustomerUsesToTextCast(): void
    {
        $tenant = Uuid::v4();
        $tender = TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => 1000]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $facts = $this->repository->factsByDimension(
            $tenant,
            'customer',
            new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $facts);
        // TO_TEXT (CAST AS text): uuid-заказчик приходит строкой, а не объектом.
        self::assertSame((string) $tenant, $facts[0]['dimension_value']);
        self::assertSame(1000, $facts[0]['nmck_minor']);
    }

    public function testFactsByDimensionPeriodUsesToChar(): void
    {
        $tenant = Uuid::v4();
        $tender = TenderFactory::createOne(['customerId' => $tenant, 'nmckMinor' => 1000]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $facts = $this->repository->factsByDimension(
            $tenant,
            'period',
            new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $facts);
        // TO_CHAR: дата создания в Y-m-d, без зависимости от гидратации.
        self::assertSame($today, $facts[0]['dimension_value']);
    }

    public function testFactsByDimensionUnsupportedDimensionReturnsEmpty(): void
    {
        $tenant = Uuid::v4();

        self::assertSame([], $this->repository->factsByDimension(
            $tenant,
            'okpd2',
            new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        ));
    }
}
