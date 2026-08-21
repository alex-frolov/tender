<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Лот тендера для функциональных тестов подачи заявок.
 *
 * У тендера с лотами заявка подаётся на лот: `lot_id` обязателен
 * (BidService::submit), потому что допуск к торгам сверяется парой
 * (тендер, лот) — заявка «на тендер целиком» к лотовому аукциону не допускает.
 * Тесты создают ровно один лот, поэтому им нужен именно его id — руками
 * протаскивать сущность лота через хелперы не приходится.
 */
trait TenderLotTrait
{
    /**
     * Id первого лота тендера (сущность уже под рукой).
     */
    private static function firstLotId(Tender $tender): string
    {
        $lot = $tender->getLots()->first();
        self::assertNotFalse($lot, 'Tender has no lots');

        return (string) $lot->getId();
    }

    /**
     * Id первого лота тендера, созданного через API (известен только его id).
     */
    private static function firstLotIdOf(string $tenderId): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $lots = $em->getRepository(Lot::class)->findBy(['tender' => Uuid::fromString($tenderId)], ['createdAt' => 'ASC'], 1);
        self::assertNotEmpty($lots, 'Tender has no lots');
        $lot = $lots[0];
        self::assertInstanceOf(Lot::class, $lot);

        return (string) $lot->getId();
    }
}
