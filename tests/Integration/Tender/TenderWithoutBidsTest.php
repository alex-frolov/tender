<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender;

use App\Bid\BidService;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Exception\ConflictException;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\TenderService;
use App\Tender\TenderStatusAggregator;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Тендер без заявки на участие (FR-1.2.1, bids_required=false).
 *
 * Фазы accepting_bids у такого тендера не существует: на bids_start он уходит
 * из published прямо в bidding, заявки не принимаются, вскрытие не планируется,
 * а торговаться может любой, кому тендер доступен. Тесты фиксируют обе ветки
 * статусной модели рядом, чтобы расхождение guard'ов было видно сразу.
 */
final class TenderWithoutBidsTest extends KernelTestCase
{
    use TenderLotTrait;

    private EntityManagerInterface $em;
    private WorkflowInterface $tenderWorkflow;
    private TenderStatusAggregator $aggregator;
    private TimelineMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->tenderWorkflow = $workflow;

        $aggregator = $container->get(TenderStatusAggregator::class);
        self::assertInstanceOf(TenderStatusAggregator::class, $aggregator);
        $this->aggregator = $aggregator;

        $handler = $container->get(TimelineMessageHandler::class);
        self::assertInstanceOf(TimelineMessageHandler::class, $handler);
        $this->handler = $handler;
    }

    /**
     * Таймлайн на bids_start открывает торги, а не приём заявок.
     */
    public function testTimelineStartOpensTradeDirectly(): void
    {
        $tender = $this->publishedTender(bidsRequired: false);

        $this->handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Tender::class)->find((string) $tender->getId());
        self::assertNotNull($reloaded);
        self::assertSame(TenderStatusEnum::BIDDING, $reloaded->getStatus());
    }

    /**
     * Guard'ы взаимоисключающи: ветка выбирается флагом, а не порядком вызовов.
     */
    public function testTransitionBranchesAreMutuallyExclusive(): void
    {
        $withoutBids = $this->publishedTender(bidsRequired: false);
        self::assertFalse($this->tenderWorkflow->can($withoutBids, TenderStatusTransition::START_BID_ACCEPTANCE->value));
        self::assertTrue($this->tenderWorkflow->can($withoutBids, TenderStatusTransition::START_TRADE_WITHOUT_BIDS->value));

        $withBids = $this->publishedTender(bidsRequired: true);
        self::assertTrue($this->tenderWorkflow->can($withBids, TenderStatusTransition::START_BID_ACCEPTANCE->value));
        self::assertFalse($this->tenderWorkflow->can($withBids, TenderStatusTransition::START_TRADE_WITHOUT_BIDS->value));
    }

    /**
     * Агрегация мультилота ведёт такой тендер из published сразу в bidding,
     * не застревая на несуществующей для него фазе accepting_bids.
     */
    public function testAggregationSkipsAcceptingBidsPhase(): void
    {
        $tender = $this->publishedTender(bidsRequired: false);
        $this->setLotStatus($tender, LotStatusEnum::BIDDING);

        $this->aggregator->recalculate($tender);

        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());
    }

    /**
     * Заявку в такой тендер подать нельзя — и сообщение отличается от «приём
     * закрыт»: приёма заявок в этой процедуре нет вовсе.
     */
    public function testBidSubmissionIsRejected(): void
    {
        $tender = $this->publishedTender(bidsRequired: false);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_TRADE_WITHOUT_BIDS->value);
        $this->em->flush();

        $bidService = self::getContainer()->get(BidService::class);
        self::assertInstanceOf(BidService::class, $bidService);
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne(['companyId' => $supplier->getId(), 'role' => UserRoleEnum::ADMIN]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('This tender does not require participation bids');

        $bidService->submit(
            actor: $user,
            tender: $tender,
            lotId: self::firstLotId($tender),
            part1: ['consent' => true],
            part2Ref: [],
            priceMinor: 900,
            priceBasis: null,
            vatRate: null,
        );
    }

    /**
     * Публикация не планирует авто-вскрытие: вскрывать нечего. Задача старта
     * процедуры (bids_start) при этом планируется как обычно — она и открывает
     * торги.
     */
    public function testPublishSchedulesStartButNotBidOpening(): void
    {
        $transport = self::getContainer()->get('messenger.transport.live');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $company = CompanyFactory::new()->approved()->create();
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
        $tender = TenderFactory::createOne([
            'nmckMinor' => 1000,
            'customerId' => $company->getId(),
            'bidsRequired' => false,
        ]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $service = self::getContainer()->get(TenderService::class);
        self::assertInstanceOf(TenderService::class, $service);
        $service->publish($user, (string) $tender->getId());

        $actions = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof TimelineMessage) {
                $actions[] = $message->action;
            }
        }

        self::assertContains(TenderTimelineAction::START_BID_ACCEPTANCE->value, $actions);
        self::assertNotContains(TenderTimelineAction::OPEN_BIDS->value, $actions);
    }

    private function publishedTender(bool $bidsRequired): Tender
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => 1000,
            'bidsRequired' => $bidsRequired,
        ]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->em->flush();

        return $tender;
    }

    private function setLotStatus(Tender $tender, LotStatusEnum $status): void
    {
        $lot = $tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);
        $lot->setStatus($status);
        $this->em->flush();
    }
}
