<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * auctions:start-scheduled (FR-1.3.1, T13): планировщик переводит
 * SCHEDULED → TRADE, когда наступил scheduled_start_at.
 *
 * - наступивший срок → TRADE, started_at и planned_end_at проставлены;
 * - будущий срок не трогается (торги начинаются не раньше времени);
 * - статусы вне SCHEDULED (NEW без даты) командой не затрагиваются.
 */
final class AuctionStartScheduledCommandTest extends KernelTestCase
{
    private static function executeStartScheduled(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('auctions:start-scheduled'));
        $tester->execute(['command' => 'auctions:start-scheduled'], ['interactive' => false]);

        return $tester;
    }

    private static function scheduledAuction(?\DateTimeImmutable $startAt, AuctionStatusEnum $status): Auction
    {
        $tender = TenderFactory::createOne();
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 500000]);

        return AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => $status, 'scheduledStartAt' => $startAt])
            ->create();
    }

    public function testStartsAuctionWhoseTimeHasCome(): void
    {
        $past = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
        $auction = self::scheduledAuction($past, AuctionStatusEnum::SCHEDULED);

        $tester = self::executeStartScheduled();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Auction::class, $auction->getId());
        self::assertInstanceOf(Auction::class, $fresh);
        self::assertSame(AuctionStatusEnum::TRADE, $fresh->getStatus());
        self::assertNotNull($fresh->getStartedAt());
        // Таймер запущен: окончание = старт + длительность шага (FR-1.3.1).
        self::assertNotNull($fresh->getPlannedEndAt());
        self::assertNotNull($fresh->getRulesSnapshot());
    }

    public function testDoesNotStartAuctionScheduledInTheFuture(): void
    {
        $future = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));
        $auction = self::scheduledAuction($future, AuctionStatusEnum::SCHEDULED);

        self::executeStartScheduled();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Auction::class, $auction->getId());
        self::assertInstanceOf(Auction::class, $fresh);
        self::assertSame(AuctionStatusEnum::SCHEDULED, $fresh->getStatus());
        self::assertNull($fresh->getPlannedEndAt());
    }

    public function testIgnoresAuctionWithoutSchedule(): void
    {
        // Аукцион, созданный без дат: остаётся NEW, планировщик его не трогает.
        $auction = self::scheduledAuction(null, AuctionStatusEnum::NEW);

        self::executeStartScheduled();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Auction::class, $auction->getId());
        self::assertInstanceOf(Auction::class, $fresh);
        self::assertSame(AuctionStatusEnum::NEW, $fresh->getStatus());
        self::assertNull($fresh->getStartedAt());
    }
}
