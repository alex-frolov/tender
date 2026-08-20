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
 * auctions:finish-expired (FR-1.3.3, T16): планировщик закрывает торги,
 * у которых истекло окно (TRADE, planned_end_at <= now) → CHOICE.
 *
 * До появления команды переход выполнял только заказчик, поэтому аукцион
 * с истёкшим таймером висел в TRADE неограниченно долго и продолжал
 * принимать ставки.
 *
 * - истёкшее окно → CHOICE, actual_end_at проставлен;
 * - окно ещё открыто → торги не трогаются;
 * - победитель не выбирается: это отдельное решение заказчика.
 */
final class AuctionFinishExpiredCommandTest extends KernelTestCase
{
    private static function executeFinishExpired(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('auctions:finish-expired'));
        $tester->execute(['command' => 'auctions:finish-expired'], ['interactive' => false]);

        return $tester;
    }

    private static function tradingAuction(\DateTimeImmutable $plannedEndAt): Auction
    {
        $tender = TenderFactory::createOne();
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 500000]);

        return AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'status' => AuctionStatusEnum::TRADE,
                'startedAt' => $plannedEndAt->modify('-10 minutes'),
                'plannedEndAt' => $plannedEndAt,
            ])
            ->create();
    }

    private static function refresh(Auction $auction): Auction
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $em->find(Auction::class, $auction->getId());
        self::assertInstanceOf(Auction::class, $fresh);

        return $fresh;
    }

    public function testFinishesAuctionWithClosedWindow(): void
    {
        $auction = self::tradingAuction(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));

        $tester = self::executeFinishExpired();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $fresh = self::refresh($auction);
        self::assertSame(AuctionStatusEnum::CHOICE, $fresh->getStatus());
        self::assertNotNull($fresh->getActualEndAt());
        // Победитель — отдельное решение заказчика, команда его не выбирает.
        self::assertNull($fresh->getWinnerBidId());
    }

    public function testDoesNotTouchAuctionWithOpenWindow(): void
    {
        $auction = self::tradingAuction(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));

        self::executeFinishExpired();

        $fresh = self::refresh($auction);
        self::assertSame(AuctionStatusEnum::TRADE, $fresh->getStatus());
        self::assertNull($fresh->getActualEndAt());
    }

    public function testIgnoresAuctionOutsideTrade(): void
    {
        // Запланированный аукцион с прошедшей датой окна командой не затрагивается:
        // закрывать нечего, торги ещё не начинались.
        $tender = TenderFactory::createOne();
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 500000]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::SCHEDULED])
            ->create();

        self::executeFinishExpired();

        $fresh = self::refresh($auction);
        self::assertSame(AuctionStatusEnum::SCHEDULED, $fresh->getStatus());
    }
}
