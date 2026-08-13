<?php

declare(strict_types=1);

namespace App\Contract;

use App\Auction\AuctionLifecycleService;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractTender;
use App\Contract\Entity\ContractType;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractSourceEnum;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Exception\ContractNotFoundException;
use App\Contract\Input\CreateContractInput;
use App\Contract\Input\SignContractInput;
use App\Contract\Repository\ContractRepository;
use App\Contract\Repository\ContractTenderRepository;
use App\Contract\Service\ContractTransaction;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use App\Shared\Input\InputValue;
use App\Shared\Money\MoneyService;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Договоры (FR-1.4.3/1.4.8, AM-9, domain/contract-state-machine.md).
 *
 * Текущий скоуп: рамочные договоры вне тендера (source=external,
 * UC-08d) для закрытых тендеров (contract_holders, FR-1.5.14), договоры по
 * итогам тендера (source=tender, после APPROVE, FR-1.4.3) и их жизненный
 * цикл: draft → pending_signature → signed → registered. Привязка тендеров
 * к договору (contract_tenders, FR-1.4.6) — bindTender() (multi_use —
 * несколько тендеров, single_use — один).
 *
 * Сервис — оркестратор: валидация (стороны, workflow-guards, инварианты),
 * резолв тендера/победителя аукциона (FR-1.4.3), привязка contract_tenders.
 * Транзакционный «хвост» (persist/flush + append-only аудит FR-1.8 + outbox
 * contract.*, workflow-переходы жизненного цикла, генерация номера) вынесен во
 * внутренний support-класс `Contract\Service\ContractTransaction`.
 *
 * - Договор создаёт заказчик (customer = компания актора, permission contracts.create);
 *   рамочный multi_use по умолчанию (FR-1.4.8);
 * - Подписание — ЭП-заглушка: обе стороны ставят подписи по отдельности
 *   (Contract::signParty), при обеих подписях workflow-переход sign (guard по
 *   флагам) переводит договор в signed (FR-1.4.3);
 * - Все переходы статуса — только через symfony/workflow (state_machine.contract).
 *
 * Валидация входных данных (обязательность, enum) — в формах (контроллер),
 * разбор id/UUID и бизнес-правила — здесь; ошибки — ApiException
 * (ValidationException/ConflictException/StateTransitionException/
 * ContractNotFoundException) → JSON через JsonApiExceptionSubscriber.
 */
final readonly class ContractService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContractRepository $contracts,
        private ContractTenderRepository $contractTenders,
        private AuctionLifecycleService $auctionLifecycle,
        private TenderReadService $tenders,
        private MoneyService $money,
        private ContractTransaction $transaction,
    ) {
    }

    /**
     * Заключение договора (FR-1.4.3/1.4.8, UC-08/UC-08d).
     * Создаёт draft; multi_use по умолчанию.
     *
     * - source=external — рамочный договор вне тендера (UC-08d): готов к
     *   использованию в закрытых тендерах (contract_holders, FR-1.5.14)
     *   после подписания обеими сторонами;
     * - source=tender — договор по итогам выигранного тендера (после APPROVE,
     *   FR-1.4.3): supplier/price определяются из победителя аукциона тендера;
     *   contract_tenders связь создаётся автоматически.
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если customer_id ≠ компании актора, source
     *                             невалиден, contract_type не найден, supplier
     *                             не найден, цены/сроки невалидны
     * @throws NotFoundException   если source=tender и тендер не найден
     *                             или не в компании актора
     */
    public function create(User $actor, CreateContractInput $input, ?string $ip = null): Contract
    {
        $customerId = InputValue::companyId($actor);

        $declaredCustomer = InputValue::uuid($input->customerId, 'customer_id');
        if (!$declaredCustomer->equals($customerId)) {
            throw new ValidationException('customer_id must match the authenticated company');
        }

        $source = $this->source($input->source);
        $scope = $this->scope($input->scope);
        $contractTypeId = $this->contractTypeId($input->contractTypeId);
        $typeCode = $this->contractTypeCode($contractTypeId);

        $supplierId = null;
        $priceNetMinor = null;
        $vatRateBps = null !== $input->vatRate ? (int) round($input->vatRate * 100) : 0;
        $validFrom = InputValue::date($input->validFrom, 'valid_from');
        $validTo = InputValue::date($input->validTo, 'valid_to');
        InputValue::assertDateRange($validFrom, $validTo, 'valid_from', 'valid_to');

        if (ContractSourceEnum::EXTERNAL === $source) {
            $supplierId = InputValue::uuid($input->supplierId, 'supplier_id');
            $this->assertSupplierExists($supplierId);
            $this->assertPrice($input->priceNetMinor);
            $priceNetMinor = $input->priceNetMinor;
        } else {
            // source=tender: победитель аукциона тендера (после APPROVE, FR-1.4.3).
            $tenderId = InputValue::uuid($input->tenderId, 'tender_id');
            $winner = $this->resolveTenderWinner($customerId, $tenderId);
            $supplierId = $winner['supplier_id'];
            $priceNetMinor = $winner['price_net_minor'];
        }

        $contract = new Contract(
            number: $this->transaction->nextNumber(),
            contractTypeId: $contractTypeId,
            customerId: $customerId,
            supplierId: $supplierId,
            source: $source,
            scope: $scope,
            priceNetMinor: $priceNetMinor,
            priceGrossMinor: null !== $priceNetMinor
                ? $this->money->netToGross($priceNetMinor, $vatRateBps)
                : null,
            vatRateBps: $vatRateBps,
            priceBasis: null !== $input->priceBasis ? $this->priceBasis($input->priceBasis) : null,
            validFrom: $validFrom,
            validTo: $validTo,
            terms: $input->terms,
        );

        $this->em->persist($contract);

        // source=tender: привязка тендера (contract_tenders, FR-1.4.6).
        if (ContractSourceEnum::TENDER === $source) {
            $this->bindTenderInternal($contract, InputValue::uuid($input->tenderId, 'tender_id'), null, null, $priceNetMinor, $vatRateBps);
        }

        $this->transaction->commitCreated($contract, $typeCode, $source->value, $scope->value, $supplierId, $actor, $ip);

        return $contract;
    }

    /**
     * Привязка тендера к договору (FR-1.4.6, POST /contracts/{contractId}/tenders).
     * Многоразовый (multi_use) — несколько тендеров на один договор; одноразовый
     * (single_use) — только один. Цена/условия по тендеру фиксируются в
     * contract_tenders (status=pending). Выполняет заказчик (customer).
     *
     * @throws ContractNotFoundException если договор не найден или актор не сторона
     * @throws ConflictException         если актор не заказчик, договор single_use
     *                                   и уже привязан тендер
     * @throws NotFoundException         если тендер не в компании актора
     * @throws ValidationException       если цена невалидна
     */
    public function bindTender(
        User $actor,
        string $contractId,
        string $tenderId,
        ?string $lotId,
        ?string $awardId,
        ?int $priceNetMinor,
        ?float $vatRate,
        ?string $ip = null,
    ): ContractTender {
        $companyId = InputValue::companyId($actor);
        $contract = $this->resolveAsParty($companyId, $contractId);
        if (!$contract->getCustomerId()->equals($companyId)) {
            throw new ConflictException('Only the customer can bind tenders to a contract');
        }

        $tenderUuid = InputValue::uuid($tenderId, 'tender_id');
        $this->assertTenderOwnedByCustomer($companyId, $tenderUuid);
        $this->assertPrice($priceNetMinor);

        if (ContractScopeEnum::SINGLE_USE === $contract->getScope() && 0 < \count($this->contractTenders->listForContract($contract))) {
            throw new ConflictException('single_use contract already has a bound tender');
        }

        $vatRateBps = null !== $vatRate ? (int) round($vatRate * 100) : $contract->getVatRateBps();

        $tender = $this->bindTenderInternal(
            $contract,
            $tenderUuid,
            null !== $lotId ? InputValue::uuid($lotId, 'lot_id') : null,
            null !== $awardId ? InputValue::uuid($awardId, 'award_id') : null,
            $priceNetMinor,
            $vatRateBps,
        );

        $this->transaction->commitBoundTender($contract, $tender, $tenderUuid, $priceNetMinor, $actor, $ip);

        return $tender;
    }

    /**
     * Создание contract_tenders записи (внутренний helper, без flush).
     */
    private function bindTenderInternal(
        Contract $contract,
        Uuid $tenderId,
        ?Uuid $lotId,
        ?Uuid $awardId,
        ?int $priceNetMinor,
        int $vatRateBps,
    ): ContractTender {
        $price = $priceNetMinor ?? 0;
        $tender = new ContractTender(
            contract: $contract,
            tenderId: $tenderId,
            lotId: $lotId,
            awardId: $awardId,
            priceNetMinor: $price,
            priceGrossMinor: $this->money->netToGross($price, $vatRateBps),
            vatRateBps: $vatRateBps,
        );
        $contract->addTender($tender);
        $this->em->persist($tender);

        return $tender;
    }

    /**
     * Победитель аукциона тендера (FR-1.4.3, source=tender): supplier/price
     * из победившей ставки аукциона в APPROVE.
     *
     * @return array{supplier_id: Uuid, price_net_minor: int}
     *
     * @throws NotFoundException        если тендер не найден/не в компании актора
     * @throws StateTransitionException если у тендера нет аукциона в APPROVE с победителем
     */
    private function resolveTenderWinner(Uuid $companyId, Uuid $tenderId): array
    {
        $this->assertTenderOwnedByCustomer($companyId, $tenderId);

        foreach ($this->auctionLifecycle->listForTender($tenderId) as $ctx) {
            if (AuctionStatusEnum::APPROVE !== $ctx->status) {
                continue;
            }

            $winningBid = $this->auctionLifecycle->winningBidResult($ctx->id);
            if (null !== $winningBid) {
                return [
                    'supplier_id' => $winningBid->bidderId,
                    'price_net_minor' => $winningBid->priceMinor,
                ];
            }
        }

        throw new StateTransitionException('No approved auction winner found for the tender');
    }

    /**
     * @throws NotFoundException если тендер не найден или не в компании актора
     */
    private function assertTenderOwnedByCustomer(Uuid $companyId, Uuid $tenderId): void
    {
        // Tenant-проверка тендера через публичный read-контракт Tender-модуля
        // (TenderReadService::belongsToCompany), а не DQL по чужой Entity
        // (границы модулей, rule 6).
        if (!$this->tenders->belongsToCompany($tenderId, $companyId)) {
            throw new NotFoundException('Tender not found');
        }
    }

    /**
     * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
     * так и исполнителя. Необязательный фильтр по статусу.
     *
     * @return list<Contract>
     */
    public function list(User $actor, ?string $status = null): array
    {
        $companyId = InputValue::companyId($actor);

        $statusEnum = null;
        if (null !== $status && '' !== $status) {
            $statusEnum = ContractStatusEnum::tryFrom($status);
            if (null === $statusEnum) {
                throw new ValidationException('invalid status');
            }
        }

        return $this->contracts->listForParties($companyId, $companyId, $statusEnum);
    }

    /**
     * Карточка договора (AM-9 GET /contracts/{contractId}). Доступ только одной
     * из сторон (customer/supplier); для чужих — 404 (party-изоляция).
     *
     * @throws ContractNotFoundException если договор не найден или не принадлежит стороне
     */
    public function get(User $actor, string $contractId): Contract
    {
        $companyId = InputValue::companyId($actor);

        return $this->resolveAsParty($companyId, $contractId);
    }

    /**
     * Отправка на подписание (C1, draft → pending_signature). Инициирует
     * заказчик (customer). Событие contract.pending_signature.
     *
     * @throws ContractNotFoundException если договор не найден или не принадлежит стороне
     * @throws ConflictException         если актор — не заказчик
     * @throws StateTransitionException  если договор не в статусе draft
     */
    public function sendForSignature(User $actor, string $contractId, ?string $ip = null): Contract
    {
        $companyId = InputValue::companyId($actor);
        $contract = $this->resolveAsParty($companyId, $contractId);
        if (!$contract->getCustomerId()->equals($companyId)) {
            throw new ConflictException('Only the customer can send the contract for signature');
        }

        $this->transaction->applySendForSignature($contract, $actor, $ip);

        return $contract;
    }

    /**
     * Подписание договора одной из сторон (C2, ЭП-заглушка, FR-1.4.3).
     * Каждая сторона подписывает свою часть (party=customer|supplier); при
     * подписях ОБЕИХ сторон workflow-переход sign переводит договор в signed
     * (guard: signedByCustomer && signedBySupplier), фиксируется signed_at и
     * публикуется contract.signed. При одной подписи договор остаётся в
     * pending_signature. Повторная подпись той же стороны — 409.
     *
     * @throws ContractNotFoundException если договор не найден или актор — не сторона
     * @throws ValidationException       если party не customer/supplier
     * @throws ConflictException         если сторона уже подписала или актор не та сторона
     * @throws StateTransitionException  если договор не в статусе pending_signature
     */
    public function sign(User $actor, string $contractId, SignContractInput $input, ?string $ip = null): Contract
    {
        $companyId = InputValue::companyId($actor);
        $contract = $this->resolveAsParty($companyId, $contractId);

        $party = $this->party($input->party);
        if ('customer' === $party) {
            if (!$contract->getCustomerId()->equals($companyId)) {
                throw new ConflictException('Only the customer can sign as customer');
            }
            if ($contract->isSignedByCustomer()) {
                throw new ConflictException('Customer has already signed this contract');
            }
        } else {
            if (!$contract->getSupplierId()->equals($companyId)) {
                throw new ConflictException('Only the supplier can sign as supplier');
            }
            if ($contract->isSignedBySupplier()) {
                throw new ConflictException('Supplier has already signed this contract');
            }
        }

        if (ContractStatusEnum::PENDING_SIGNATURE !== $contract->getStatus()) {
            throw new StateTransitionException('Only contracts pending signature can be signed');
        }

        $contract->signParty('customer' === $party, (string) $input->signature);

        $this->transaction->commitSigned($contract, $party, $actor, $ip);

        return $contract;
    }

    /**
     * Регистрация договора в учёте (C6, signed → registered). Фиксируется
     * registered_at; событие contract.registered. Вызывается заказчиком.
     *
     * @throws ContractNotFoundException если договор не найден или не принадлежит стороне
     * @throws ConflictException         если актор — не заказчик
     * @throws StateTransitionException  если договор не в статусе signed
     */
    public function register(User $actor, string $contractId, ?string $ip = null): Contract
    {
        $companyId = InputValue::companyId($actor);
        $contract = $this->resolveAsParty($companyId, $contractId);
        if (!$contract->getCustomerId()->equals($companyId)) {
            throw new ConflictException('Only the customer can register the contract');
        }

        $this->transaction->applyRegister($contract, $actor, $ip);

        return $contract;
    }

    /**
     * @throws ContractNotFoundException
     */
    private function resolveAsParty(Uuid $companyId, string $contractId): Contract
    {
        $contract = $this->contracts->findById($contractId);
        if (null === $contract) {
            throw new ContractNotFoundException('Contract not found');
        }

        if (!$contract->getCustomerId()->equals($companyId) && !$contract->getSupplierId()->equals($companyId)) {
            throw new ContractNotFoundException('Contract not found');
        }

        return $contract;
    }

    /**
     * @throws ValidationException
     */
    private function source(?string $value): ContractSourceEnum
    {
        if (null === $value || '' === $value) {
            return ContractSourceEnum::EXTERNAL;
        }

        return ContractSourceEnum::tryFrom($value)
            ?? throw new ValidationException('invalid source');
    }

    /**
     * @throws ValidationException
     */
    private function scope(?string $value): ContractScopeEnum
    {
        if (null === $value || '' === $value) {
            return ContractScopeEnum::MULTI_USE;
        }

        return ContractScopeEnum::tryFrom($value)
            ?? throw new ValidationException('invalid scope');
    }

    /**
     * Тип договора — из справочника contract_types (FR-1.4.3); стартовый base.
     *
     * @throws ValidationException если id невалиден или тип не найден/неактивен
     */
    private function contractTypeId(?string $value): int
    {
        if (null === $value || '' === $value || !ctype_digit($value)) {
            throw new ValidationException('contract_type_id is required');
        }

        $id = (int) $value;
        /** @var ContractType|null $type */
        $type = $this->em->find(ContractType::class, $id);
        if (null === $type || !$type->isActive()) {
            throw new ValidationException('contract_type not found or inactive');
        }

        return $id;
    }

    /**
     * Код типа договора для outbox-события contract.created.
     */
    private function contractTypeCode(int $contractTypeId): string
    {
        /** @var ContractType|null $type */
        $type = $this->em->find(ContractType::class, $contractTypeId);

        return null !== $type ? $type->getCode() : '';
    }

    /**
     * @throws ValidationException если компания-исполнитель не найдена
     */
    private function assertSupplierExists(Uuid $supplierId): void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('1')
            ->from(\App\Iam\Entity\Company::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $supplierId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $qb) {
            throw new ValidationException('supplier company not found');
        }
    }

    /**
     * Цена договора — неотрицательная (если указана).
     *
     * @throws ValidationException
     */
    private function assertPrice(?int $priceMinor): void
    {
        if (null !== $priceMinor && $priceMinor < 0) {
            throw new ValidationException('price_net_minor must be non-negative');
        }
    }

    /**
     * @throws ValidationException
     */
    private function priceBasis(string $value): PriceBasisEnum
    {
        return PriceBasisEnum::tryFrom($value)
            ?? throw new ValidationException('invalid price_basis');
    }

    private function party(?string $value): string
    {
        $party = $value ?? '';
        if (!\in_array($party, ['customer', 'supplier'], true)) {
            throw new ValidationException('party must be customer or supplier');
        }

        return $party;
    }
}
