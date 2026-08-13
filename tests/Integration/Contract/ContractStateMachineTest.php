<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Entity\Enum\ContractStatusTransition;
use App\Tests\Factory\ContractFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Contract state machine (FR-1.4.3, domain/contract-state-machine.md, M5).
 *
 * Тесты-таблицы переходов: полный цикл draft → pending_signature → signed
 * (guard: подписи ОБЕИХ сторон) → registered; возврат на доработку, расторжение,
 * истечение срока, мягкое удаление; запрещённые переходы блокируются. Все
 * переходы — через symfony/workflow (state_machine.contract).
 */
final class ContractStateMachineTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowInterface $contractWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $workflow = $container->get('state_machine.contract');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Contract workflow not resolvable');
        }
        $this->contractWorkflow = $workflow;
    }

    /**
     * C0–C2+C6: инициация (draft) → отправка на подписание → подписи обеих
     * сторон → signed → registered.
     */
    public function testFullLifecycleDraftToRegistered(): void
    {
        $contract = $this->draftContract();
        self::assertSame(ContractStatusEnum::DRAFT, $contract->getStatus());

        // Сразу подписать из draft нельзя (нет перехода sign из draft).
        self::assertFalse($this->contractWorkflow->can($contract, ContractStatusTransition::SIGN->value));

        // C1: draft → pending_signature.
        $this->contractWorkflow->apply($contract, ContractStatusTransition::SEND_FOR_SIGNATURE->value);
        self::assertSame(ContractStatusEnum::PENDING_SIGNATURE, $contract->getStatus());

        // Одна подпись (заказчик): договор остаётся pending_signature, guard блокирует.
        $contract->signParty(true, 'sign-customer');
        $this->em->flush();
        self::assertSame(ContractStatusEnum::PENDING_SIGNATURE, $contract->getStatus());
        self::assertFalse($this->contractWorkflow->can($contract, ContractStatusTransition::SIGN->value));

        // Вторая подпись (исполнитель): guard пройден → signed.
        $contract->signParty(false, 'sign-supplier');
        $this->em->flush();
        self::assertTrue($this->contractWorkflow->can($contract, ContractStatusTransition::SIGN->value));
        $this->contractWorkflow->apply($contract, ContractStatusTransition::SIGN->value);
        self::assertSame(ContractStatusEnum::SIGNED, $contract->getStatus());

        // C6: signed → registered.
        $this->contractWorkflow->apply($contract, ContractStatusTransition::REGISTER->value);
        self::assertSame(ContractStatusEnum::REGISTERED, $contract->getStatus());
    }

    /**
     * C3: возврат на доработку (pending_signature → draft).
     */
    public function testBackToDraft(): void
    {
        $contract = $this->pendingContract();

        $this->contractWorkflow->apply($contract, ContractStatusTransition::BACK_TO_DRAFT->value);
        self::assertSame(ContractStatusEnum::DRAFT, $contract->getStatus());
    }

    /**
     * C7/C9: расторжение signed и registered → terminated.
     */
    public function testTerminateFromSignedAndRegistered(): void
    {
        $signed = $this->signedContract();
        $this->contractWorkflow->apply($signed, ContractStatusTransition::TERMINATE->value);
        self::assertSame(ContractStatusEnum::TERMINATED, $signed->getStatus());

        $registered = $this->signedContract();
        $this->contractWorkflow->apply($registered, ContractStatusTransition::REGISTER->value);
        $this->contractWorkflow->apply($registered, ContractStatusTransition::TERMINATE->value);
        self::assertSame(ContractStatusEnum::TERMINATED, $registered->getStatus());
    }

    /**
     * C8/C10: истечение срока по valid_to (signed/registered → expired).
     */
    public function testExpireFromSignedAndRegistered(): void
    {
        $signed = $this->signedContract();
        $this->contractWorkflow->apply($signed, ContractStatusTransition::EXPIRE->value);
        self::assertSame(ContractStatusEnum::EXPIRED, $signed->getStatus());

        $registered = $this->signedContract();
        $this->contractWorkflow->apply($registered, ContractStatusTransition::REGISTER->value);
        $this->contractWorkflow->apply($registered, ContractStatusTransition::EXPIRE->value);
        self::assertSame(ContractStatusEnum::EXPIRED, $registered->getStatus());
    }

    /**
     * C4/C5/C11/C12: мягкое удаление черновика и подписанного договора → deleted.
     */
    public function testDeleteFromDraftAndRegistered(): void
    {
        $draft = $this->draftContract();
        $this->contractWorkflow->apply($draft, ContractStatusTransition::DELETE->value);
        self::assertSame(ContractStatusEnum::DELETED, $draft->getStatus());

        $registered = $this->signedContract();
        $this->contractWorkflow->apply($registered, ContractStatusTransition::REGISTER->value);
        $this->contractWorkflow->apply($registered, ContractStatusTransition::DELETE->value);
        self::assertSame(ContractStatusEnum::DELETED, $registered->getStatus());
    }

    /**
     * Терминальные статусы необратимы: из terminated/expired/deleted нет переходов.
     */
    public function testTerminalStatesAreFinal(): void
    {
        $terminated = $this->signedContract();
        $this->contractWorkflow->apply($terminated, ContractStatusTransition::TERMINATE->value);
        self::assertFalse($this->contractWorkflow->can($terminated, ContractStatusTransition::REGISTER->value));

        $expired = $this->signedContract();
        $this->contractWorkflow->apply($expired, ContractStatusTransition::EXPIRE->value);
        self::assertFalse($this->contractWorkflow->can($expired, ContractStatusTransition::TERMINATE->value));
    }

    private function draftContract(): Contract
    {
        $contract = ContractFactory::createOne();
        self::assertInstanceOf(Contract::class, $contract);

        return $contract;
    }

    private function pendingContract(): Contract
    {
        $contract = $this->draftContract();
        $this->contractWorkflow->apply($contract, ContractStatusTransition::SEND_FOR_SIGNATURE->value);

        return $contract;
    }

    private function signedContract(): Contract
    {
        $contract = $this->pendingContract();
        $contract->signParty(true, 'sign-customer');
        $contract->signParty(false, 'sign-supplier');
        $this->em->flush();
        $this->contractWorkflow->apply($contract, ContractStatusTransition::SIGN->value);

        return $contract;
    }
}
