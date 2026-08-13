<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender;

use App\Shared\Exception\ValidationException;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tender\TenderCatalogQuery;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Read-модель каталога тендеров (FR-1.1.1, AR-6, NFR-22): keyset-пагинация
 * по (created_at, id), фильтр по статусу, агрегированный статус при мультилоте
 * (FR-1.1.3) и lot_count по id страницы, OPAQUE-курсор, невалидный курсор → 422.
 */
final class TenderCatalogQueryTest extends KernelTestCase
{
    private TenderCatalogQuery $query;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(TenderCatalogQuery::class);
        if (!$query instanceof TenderCatalogQuery) {
            throw new \LogicException('TenderCatalogQuery not resolvable');
        }
        $this->query = $query;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('EntityManager not resolvable');
        }
        $this->em = $em;
    }

    /**
     * Порядок страниц: created_at DESC (с tiebreaker id при равных секундах),
     * курсор проходит все тендеры без пропусков и дублей; на последней странице
     * next_cursor = null.
     */
    public function testKeysetPaginationWalksAllTendersInCreatedAtDescOrder(): void
    {
        $tenant = Uuid::v4();
        $tenders = [];
        foreach (['T1', 'T2', 'T3', 'T4', 'T5'] as $title) {
            $tenders[$title] = TenderFactory::createOne(['customerId' => $tenant, 'title' => $title]);
        }
        // детерминированные created_at: T1 самая старая, T5 самая свежая
        $this->setCreatedAt($tenders['T1'], '2026-01-01T00:00:01+00:00');
        $this->setCreatedAt($tenders['T2'], '2026-01-01T00:00:02+00:00');
        $this->setCreatedAt($tenders['T3'], '2026-01-01T00:00:03+00:00');
        $this->setCreatedAt($tenders['T4'], '2026-01-01T00:00:04+00:00');
        $this->setCreatedAt($tenders['T5'], '2026-01-01T00:00:05+00:00');

        $page1 = $this->query->page($tenant, null, null, 2);
        self::assertCount(2, $page1->items);
        self::assertSame('T5', $page1->items[0]['title']);
        self::assertSame('T4', $page1->items[1]['title']);
        self::assertNotNull($page1->nextCursor);

        $page2 = $this->query->page($tenant, null, $page1->nextCursor, 2);
        self::assertCount(2, $page2->items);
        self::assertSame('T3', $page2->items[0]['title']);
        self::assertSame('T2', $page2->items[1]['title']);
        self::assertNotNull($page2->nextCursor);

        $page3 = $this->query->page($tenant, null, $page2->nextCursor, 2);
        self::assertCount(1, $page3->items);
        self::assertSame('T1', $page3->items[0]['title']);
        self::assertNull($page3->nextCursor);
    }

    public function testFilterByStatusReturnsOnlyMatchingTenders(): void
    {
        $tenant = Uuid::v4();
        TenderFactory::createOne(['customerId' => $tenant, 'title' => 'Draft']);
        $published = TenderFactory::createOne(['customerId' => $tenant, 'title' => 'Published']);
        $published->setStatus(TenderStatusEnum::PUBLISHED);
        $this->em->flush();

        $page = $this->query->page($tenant, TenderStatusEnum::PUBLISHED, null, 20);

        self::assertCount(1, $page->items);
        self::assertSame('Published', $page->items[0]['title']);
    }

    public function testAggregatedStatusAndLotCountPerPageId(): void
    {
        $tenant = Uuid::v4();

        // мультилот: bidding + accepting_bids → aggregated accepting_bids (вариант C)
        $multi = TenderFactory::createOne(['customerId' => $tenant, 'title' => 'Multi', 'nmckMinor' => 2000]);
        $l1 = LotFactory::createOne(['tender' => $multi, 'priceNetMinor' => 1000, 'number' => 1]);
        $l2 = LotFactory::createOne(['tender' => $multi, 'priceNetMinor' => 1000, 'number' => 2]);
        $l1->setStatus(LotStatusEnum::BIDDING);
        $l2->setStatus(LotStatusEnum::ACCEPTING_BIDS);

        // без лотов → административный статус (draft), lot_count 0
        $empty = TenderFactory::createOne(['customerId' => $tenant, 'title' => 'Empty', 'noStartPrice' => true, 'nmckMinor' => null]);

        $page = $this->query->page($tenant, null, null, 20);
        self::assertCount(2, $page->items);

        $byTitle = [];
        foreach ($page->items as $item) {
            $byTitle[$item['title']] = $item;
        }

        self::assertSame('accepting_bids', $byTitle['Multi']['aggregated_status']->value);
        self::assertSame(2, $byTitle['Multi']['lot_count']);
        self::assertSame('draft', $byTitle['Empty']['aggregated_status']->value);
        self::assertSame(0, $byTitle['Empty']['lot_count']);
    }

    public function testInvalidCursorThrowsValidationException(): void
    {
        $tenant = Uuid::v4();
        TenderFactory::createOne(['customerId' => $tenant]);

        $this->expectException(ValidationException::class);

        $this->query->page($tenant, null, '!!!not-a-cursor!!!', 20);
    }

    private function setCreatedAt(Tender $tender, string $at): void
    {
        $this->em->createQuery('UPDATE App\Tender\Entity\Tender t SET t.createdAt = :at WHERE t.id = :id')
            ->setParameter('at', new \DateTimeImmutable($at, new \DateTimeZone('UTC')))
            ->setParameter('id', $tender->getId())
            ->execute();
    }
}
