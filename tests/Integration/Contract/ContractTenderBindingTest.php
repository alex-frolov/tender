<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Contract\ContractService;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractSourceEnum;
use App\Contract\Input\CreateContractInput;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 5.4: contract_tenders (FR-1.4.6).
 *
 * - create source=tender: supplier/price из победителя аукциона (после APPROVE),
 *   contract_tenders привязка создаётся автоматически (status=pending);
 * - bindTender: многоразовый (multi_use) — несколько тендеров на один договор;
 *   одноразовый (single_use) — только один (повторная привязка → 409);
 * - создание рамочного договора (source=external) не создаёт contract_tenders.
 */
final class ContractTenderBindingTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private EntityManagerInterface $em;
    private ContractService $contractService;
    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private AuctionWinnerService $winnerService;
    private WorkflowInterface $auctionWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $service = $container->get(ContractService::class);
        if (!$service instanceof ContractService) {
            throw new \LogicException('ContractService not resolvable');
        }
        $this->contractService = $service;

        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $this->bidService = $bidService;

        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $this->auctionService = $auctionService;

        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }
        $this->winnerService = $winnerService;

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;
    }

    /**
     * @return array{customer: \App\Iam\Entity\Company, supplier: \App\Iam\Entity\Company,
     *               customerUser: \App\Iam\Entity\User, auction: Auction, supplierId: Uuid}
     */
    private function approvedAuction(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'bind-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierId = $supplier->getId();

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction);
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
        $this->winnerService->selectWinnerAutomatic($auction);

        return [
            'customer' => $customer,
            'supplier' => $supplier,
            'customerUser' => $customerUser,
            'auction' => $auction,
            'supplierId' => $supplierId,
        ];
    }

    /**
     * Второй выигранный тендер ТОГО ЖЕ заказчика (для multi_use, FR-1.4.6).
     *
     * @return array{auction: Auction, supplierId: Uuid}
     */
    private function approvedAuctionForCustomer(\App\Iam\Entity\Company $customer): array
    {
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierId = $supplier->getId();

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction);
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
        $this->winnerService->selectWinnerAutomatic($auction);

        return ['auction' => $auction, 'supplierId' => $supplierId];
    }

    private function contractTypeId(): int
    {
        return (int) ContractTypeFactory::createOne()->getId();
    }

    public function testCreateSourceTenderBindsWinnerSupplierAndPrice(): void
    {
        $ctx = $this->approvedAuction();
        $auction = $ctx['auction'];
        $tender = $auction->getTenderId();

        $input = new CreateContractInput();
        $input->source = ContractSourceEnum::TENDER->value;
        $input->contractTypeId = (string) $this->contractTypeId();
        $input->tenderId = (string) $tender;
        $input->customerId = (string) $ctx['customer']->getId();
        $input->scope = ContractScopeEnum::MULTI_USE->value;

        $contract = $this->contractService->create($ctx['customerUser'], $input);

        self::assertSame(ContractSourceEnum::TENDER, $contract->getSource());
        self::assertSame((string) $ctx['supplierId'], (string) $contract->getSupplierId());
        self::assertSame(self::START_MINOR - self::STEP_MINOR, $contract->getPriceNetMinor());
        self::assertCount(1, $contract->getTenders());
        $first = $contract->getTenders()->first();
        self::assertNotFalse($first);
        self::assertSame((string) $tender, (string) $first->getTenderId());
    }

    public function testBindTenderMultiUseAllowsMultipleTenders(): void
    {
        $ctx = $this->approvedAuction();
        $auction = $ctx['auction'];
        $tender = $auction->getTenderId();

        // Рамочный multi_use договор.
        $input = new CreateContractInput();
        $input->source = ContractSourceEnum::EXTERNAL->value;
        $input->contractTypeId = (string) $this->contractTypeId();
        $input->supplierId = (string) $ctx['supplierId'];
        $input->customerId = (string) $ctx['customer']->getId();
        $input->scope = ContractScopeEnum::MULTI_USE->value;
        $input->priceNetMinor = 5000000;
        $contract = $this->contractService->create($ctx['customerUser'], $input);

        // Привязка первого тендера.
        $ct1 = $this->contractService->bindTender(
            $ctx['customerUser'],
            (string) $contract->getId(),
            (string) $tender,
            null,
            null,
            self::START_MINOR - self::STEP_MINOR,
            null,
        );
        self::assertSame((string) $tender, (string) $ct1->getTenderId());

        // Второй выигранный тендер того же заказчика.
        $ctx2 = $this->approvedAuctionForCustomer($ctx['customer']);
        $auction2 = $ctx2['auction'];
        $tender2 = $auction2->getTenderId();

        $ct2 = $this->contractService->bindTender(
            $ctx['customerUser'],
            (string) $contract->getId(),
            (string) $tender2,
            null,
            null,
            self::START_MINOR - self::STEP_MINOR,
            null,
        );
        self::assertSame((string) $tender2, (string) $ct2->getTenderId());

        // Два тендера на один multi_use договор (FR-1.4.6).
        $this->em->refresh($contract);
        self::assertCount(2, $contract->getTenders());
    }

    public function testBindTenderSingleUseRejectsSecond(): void
    {
        $ctx = $this->approvedAuction();
        $auction = $ctx['auction'];
        $tender = $auction->getTenderId();

        $input = new CreateContractInput();
        $input->source = ContractSourceEnum::EXTERNAL->value;
        $input->contractTypeId = (string) $this->contractTypeId();
        $input->supplierId = (string) $ctx['supplierId'];
        $input->customerId = (string) $ctx['customer']->getId();
        $input->scope = ContractScopeEnum::SINGLE_USE->value;
        $input->priceNetMinor = 5000000;
        $contract = $this->contractService->create($ctx['customerUser'], $input);

        $this->contractService->bindTender(
            $ctx['customerUser'],
            (string) $contract->getId(),
            (string) $tender,
            null,
            null,
            self::START_MINOR - self::STEP_MINOR,
            null,
        );

        $ctx2 = $this->approvedAuctionForCustomer($ctx['customer']);
        $auction2 = $ctx2['auction'];
        $tender2 = $auction2->getTenderId();

        try {
            $this->contractService->bindTender(
                $ctx['customerUser'],
                (string) $contract->getId(),
                (string) $tender2,
                null,
                null,
                self::START_MINOR - self::STEP_MINOR,
                null,
            );
            self::fail('Expected ConflictException for single_use second binding');
        } catch (\App\Shared\Exception\ConflictException) {
            self::addToAssertionCount(1);
        }
    }
}
