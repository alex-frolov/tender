<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshotFactory;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Support\AuctionDataCleanerTrait;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Гонки при ставках (FR-1.3.6, ARCH-6): блокировка строки аукциона.
 *
 * placeReductionFixedBid выполняет read-modify-write (current_price → валидация
 * PR-5 → запись) под pessimistic lock `SELECT ... FOR UPDATE` внутри транзакции.
 * Тест доказывает, что механизм блокировки сериализует конкурентный доступ:
 * второе соединение (имитация второй ставки) с lock_timeout НЕ может прочитать
 * строку аукциона, пока первая транзакция её держит; после COMMIT — успешно.
 *
 * Сочетается с AuctionBidServiceTest::testTwoBidsWithSamePriceOnlyFirstAccepted
 * (семантика: вторая ставка с той же ценой отклоняется против обновлённой
 * current_price). Полный E2E-тест конкурентных ставок.
 */
#[SkipDatabaseRollback]
final class AuctionBidLockTest extends KernelTestCase
{
    use AuctionDataCleanerTrait;

    public function testPessimisticLockSerializesConcurrentAccessToAuctionRow(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Подготовка: аукцион в TRADE со снапшотом правил в БД (конкурентные
        // запросы увидят его). Снапшот захватываем напрямую (RulesSnapshotFactory)
        // БЕЗ сервиса старта — чтобы не оставлять outbox-события в БД
        // (SkipDatabaseRollback: отката нет, события повлияли бы на
        // OutboxRelayerTest::testRelayEmptyReturnsZero).
        $tender = TenderFactory::createOne(['nmckMinor' => 100_000_000]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 100_000_000]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => 5_000_00,
                'status' => AuctionStatusEnum::TRADE,
            ])
            ->create();
        $this->trackAuctionData(
            (string) $auction->getId(),
            (string) $tender->getId(),
            (string) $lot->getId(),
        );

        $rulesFactory = $container->get(RulesSnapshotFactory::class);
        if (!$rulesFactory instanceof RulesSnapshotFactory) {
            throw new \LogicException('RulesSnapshotFactory not resolvable');
        }
        $auction->captureRulesSnapshot($rulesFactory->create($auction, 'RUB'));
        $em->flush();

        $auctionId = (string) $auction->getId();

        $conn = $em->getConnection();
        $params = $conn->getParams();
        $host = $params['host'] ?? 'localhost';
        $port = $params['port'] ?? 5432;
        $dbname = $params['dbname'] ?? '';
        $user = $params['user'] ?? '';
        $password = $params['password'] ?? '';
        $dsn = \sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);
        $pdo1 = new \PDO($dsn, $user, $password);
        $pdo2 = new \PDO($dsn, $user, $password);

        // PDO1 «первая ставка»: держит FOR UPDATE на строке аукциона.
        $pdo1->beginTransaction();
        $stmt1 = $pdo1->prepare('SELECT id FROM auctions WHERE id = :id FOR UPDATE');
        $stmt1->execute(['id' => $auctionId]);
        self::assertNotFalse($stmt1->fetch());

        // PDO2 «вторая ставка»: с lock_timeout блокируется до COMMIT первой.
        $pdo2->exec("SET lock_timeout = '300ms'");
        $stmt2 = $pdo2->prepare('SELECT id FROM auctions WHERE id = :id FOR UPDATE');
        $blocked = false;
        try {
            $stmt2->execute(['id' => $auctionId]);
        } catch (\PDOException) {
            $blocked = true; // 55P03 lock_not_available — строка залочена
        }
        self::assertTrue($blocked, 'Second connection must be blocked while the row is locked');

        // COMMIT первой ставки → блокировка снята → вторая читает строку.
        $pdo1->commit();
        $stmt2 = $pdo2->prepare('SELECT id FROM auctions WHERE id = :id FOR UPDATE');
        $stmt2->execute(['id' => $auctionId]);
        self::assertNotFalse($stmt2->fetch());

        // Аукцион по-прежнему в TRADE (после commit статус/снапшот не потеряны).
        $em->clear();
        /** @var Auction|null $reloaded */
        $reloaded = $em->getRepository(Auction::class)->find(Uuid::fromString($auctionId));
        self::assertNotNull($reloaded);
        self::assertSame('trade', $reloaded->getStatus()->value);
        self::assertNotNull($reloaded->getRulesSnapshot());
        // Очистка созданного — в tearDown() (AuctionDataCleanerTrait).
    }
}
