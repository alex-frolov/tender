<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Bid\Entity\Bid;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\SecurityBasisEnum;
use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Entity\Enum\SecurityStatusEnum;
use App\Contract\SecurityService;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Задача 5.8: обеспечение заявок/контрактов (FR-1.4.1/1.4.2, UC-09, B5).
 *
 * - createBidSecurity: обеспечение заявки = % НМЦК (SecurityRules, минимум
 *   диапазона); при no_start_price база — первая ставка (first_bid, B5);
 * - createContractSecurity: обеспечение исполнения контракта = % цены;
 * - release/forfeit: возврат/удержание только активного обеспечения.
 */
final class SecurityServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SecurityService $securityService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $service = $container->get(SecurityService::class);
        if (!$service instanceof SecurityService) {
            throw new \LogicException('SecurityService not resolvable');
        }
        $this->securityService = $service;
    }

    /**
     * @return array{customer: \App\Iam\Entity\Company, supplier: \App\Iam\Entity\Company,
     *               customerUser: \App\Iam\Entity\User, supplierUser: \App\Iam\Entity\User}
     */
    private function parties(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'sec-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'sec-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        return [
            'customer' => $customer,
            'supplier' => $supplier,
            'customerUser' => $customerUser,
            'supplierUser' => $supplierUser,
        ];
    }

    public function testCreateBidSecurityFromNmck(): void
    {
        $ctx = $this->parties();
        $tender = TenderFactory::createOne([
            'nmckMinor' => 100_000_00,
            'customerId' => $ctx['customer']->getId(),
            'securityRequired' => true,
        ]);
        $bid = BidFactory::createOne(['tenderId' => $tender->getId(), 'lotId' => null, 'tenantId' => $tender->getTenantId(), 'supplierId' => $ctx['supplier']->getId()]);

        $security = $this->securityService->createBidSecurity($ctx['supplierUser'], $tender->getId(), $bid->getId());

        // Минимальный % диапазона SecurityRules для bid = 0.5% (50 bps):
        // 100 000.00 ₽ × 0.5% = 500.00 ₽ = 50_000 minor.
        self::assertSame(SecurityKindEnum::BID, $security->getKind());
        self::assertSame(50_000, $security->getAmountMinor());
        self::assertSame(SecurityBasisEnum::NMCK, $security->getCalculationBasis());
        self::assertSame(100_000_00, $security->getBasisAmountMinor());
        self::assertSame(SecurityStatusEnum::ACTIVE, $security->getStatus());
        self::assertSame((string) $ctx['supplier']->getId(), (string) $security->getSupplierId());
    }

    public function testCreateBidSecurityFromFirstBid(): void
    {
        $ctx = $this->parties();
        // no_start_price=true (B5): НМЦК отсутствует, база — первая ставка.
        $tender = TenderFactory::createOne([
            'nmckMinor' => null,
            'noStartPrice' => true,
            'customerId' => $ctx['customer']->getId(),
        ]);
        $bid = BidFactory::createOne(['tenderId' => $tender->getId(), 'lotId' => null, 'tenantId' => $tender->getTenantId(), 'supplierId' => $ctx['supplier']->getId()]);
        $firstBidMinor = 80_000_00;

        $security = $this->securityService->createBidSecurity($ctx['supplierUser'], $tender->getId(), $bid->getId(), $firstBidMinor);

        self::assertSame(SecurityBasisEnum::FIRST_BID, $security->getCalculationBasis());
        // 80 000.00 ₽ × 0.5% = 400.00 ₽ = 40_000 minor.
        self::assertSame(40_000, $security->getAmountMinor());
        self::assertSame($firstBidMinor, $security->getBasisAmountMinor());
    }

    public function testCreateBidSecurityWithoutBasisFails(): void
    {
        $ctx = $this->parties();
        // no_start_price, но первая ставка ещё не зафиксирована (B5: до фиксации
        // обеспечение не требуется) → 422.
        $tender = TenderFactory::createOne([
            'nmckMinor' => null,
            'noStartPrice' => true,
            'customerId' => $ctx['customer']->getId(),
        ]);
        $bid = BidFactory::createOne(['tenderId' => $tender->getId(), 'lotId' => null, 'tenantId' => $tender->getTenantId(), 'supplierId' => $ctx['supplier']->getId()]);

        try {
            $this->securityService->createBidSecurity($ctx['supplierUser'], $tender->getId(), $bid->getId());
            self::fail('Expected ValidationException without first bid basis');
        } catch (\App\Shared\Exception\ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCreateContractSecurityAndRelease(): void
    {
        $ctx = $this->parties();
        $type = ContractTypeFactory::createOne();
        $contract = new Contract(
            number: 'C-SEC-1',
            contractTypeId: (int) $type->getId(),
            customerId: $ctx['customer']->getId(),
            supplierId: $ctx['supplier']->getId(),
            priceNetMinor: 10_000_000,
        );
        $this->em->persist($contract);
        $this->em->flush();

        // Обеспечение исполнения контракта = 5% (минимум диапазона contract):
        // 100 000.00 ₽ × 5% = 5 000.00 ₽ = 500_000 minor.
        $security = $this->securityService->createContractSecurity($ctx['customerUser'], $contract);
        self::assertSame(SecurityKindEnum::CONTRACT, $security->getKind());
        self::assertSame(500_000, $security->getAmountMinor());

        // Возврат после успешного исполнения.
        $released = $this->securityService->release($ctx['customerUser'], (string) $security->getId());
        self::assertSame(SecurityStatusEnum::RELEASED, $released->getStatus());
    }

    public function testForfeitOnlyActiveAndByCustomer(): void
    {
        $ctx = $this->parties();
        $type = ContractTypeFactory::createOne();
        $contract = new Contract(
            number: 'C-SEC-2',
            contractTypeId: (int) $type->getId(),
            customerId: $ctx['customer']->getId(),
            supplierId: $ctx['supplier']->getId(),
            priceNetMinor: 10_000_000,
        );
        $this->em->persist($contract);
        $this->em->flush();

        $security = $this->securityService->createContractSecurity($ctx['customerUser'], $contract);

        // Удержание (нарушение) заказчиком.
        $forfeited = $this->securityService->forfeit($ctx['customerUser'], (string) $security->getId());
        self::assertSame(SecurityStatusEnum::FORFEITED, $forfeited->getStatus());

        // Повторный возврат удержанного → 409.
        try {
            $this->securityService->release($ctx['customerUser'], (string) $security->getId());
            self::fail('Expected ConflictException for releasing forfeited security');
        } catch (\App\Shared\Exception\ConflictException) {
            self::addToAssertionCount(1);
        }
    }
}
