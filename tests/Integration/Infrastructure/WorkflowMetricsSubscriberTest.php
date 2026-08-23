<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure;

use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractStatusTransition;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tests\Factory\ContractFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * WorkflowMetricsSubscriber: каждый выполненный переход workflow
 * tender/contract инкрементит tender_transitions_total / contract_transitions_total.
 * Другие workflow (auction, user_status, ...) счётчиком не затрагиваются.
 */
final class WorkflowMetricsSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private WorkflowInterface $tenderWorkflow;

    private WorkflowInterface $contractWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $tenderWorkflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $tenderWorkflow);
        $this->tenderWorkflow = $tenderWorkflow;

        $contractWorkflow = $container->get('state_machine.contract');
        self::assertInstanceOf(WorkflowInterface::class, $contractWorkflow);
        $this->contractWorkflow = $contractWorkflow;
    }

    public function testTenderTransitionIncrementsCounter(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 1000]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $before = $this->seriesValue('tender_transitions_total{transition="publish"}');

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->em->flush();

        self::assertSame($before + 1, $this->seriesValue('tender_transitions_total{transition="publish"}'));
    }

    public function testContractTransitionIncrementsCounter(): void
    {
        $contract = ContractFactory::createOne();
        self::assertInstanceOf(Contract::class, $contract);

        $before = $this->seriesValue('contract_transitions_total{transition="send_for_signature"}');

        $this->contractWorkflow->apply($contract, ContractStatusTransition::SEND_FOR_SIGNATURE->value);
        $this->em->flush();

        self::assertSame($before + 1, $this->seriesValue('contract_transitions_total{transition="send_for_signature"}'));
    }

    /**
     * Значение серии из рендера /metrics; отсутствующая серия = 0
     * (Redis-хранилище общее между тестами — считаем дельту).
     */
    private function seriesValue(string $series): float
    {
        $registry = self::getContainer()->get(CollectorRegistry::class);
        self::assertInstanceOf(CollectorRegistry::class, $registry);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        if (1 === preg_match('/'.preg_quote($series, '/').' ([\d.]+)/', $body, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }
}
