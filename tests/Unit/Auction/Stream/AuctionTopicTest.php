<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction\Stream;

use App\Auction\Stream\AuctionTopic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Приватный topic Mercure для live-аукциона (FR-1.3.4, ADR-003): `auction:{id}`.
 */
final class AuctionTopicTest extends TestCase
{
    public function testTopicFormat(): void
    {
        $id = Uuid::v4();

        self::assertSame('auction:'.$id, AuctionTopic::for((string) $id));
    }
}
