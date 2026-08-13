<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Contract\Entity\Contract;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Security\ContractVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Voter прав на договоры (FR-1.4.3, FR-1.5.10/1.5.15).
 * - CREATE (subject=null): результат = can('contracts.create');
 * - SIGN (subject=Contract): заказчик — по праву contracts.sign, исполнитель —
 *   по принадлежности к компании-исполнителю (subject); не-сторона без права — denied.
 * Проверяем также: abstain для неизвестного атрибута, deny для не-App-пользователя.
 */
final class ContractVoterTest extends TestCase
{
    private ContractVoter $voter;

    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $permissions;

    protected function setUp(): void
    {
        $this->permissions = self::createStub(PermissionCheckerInterface::class);
        $this->permissions->method('can')->willReturn(true);
        $this->voter = new ContractVoter($this->permissions);
    }

    private function user(UserRoleEnum $role, ?Uuid $companyId = null): User
    {
        return new User('user@test.ru', 'Тест', $role, $companyId);
    }

    private function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function contract(): Contract
    {
        return new Contract(
            number: 'C-000001',
            contractTypeId: 1,
            customerId: Uuid::v4(),
            supplierId: Uuid::v4(),
        );
    }

    public function testCreateDelegatesToPermissionCheck(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $admin = $this->user(UserRoleEnum::ADMIN);
        $permissions->expects(self::once())
            ->method('can')
            ->with($admin, 'contracts.create')
            ->willReturn(true);
        $voter = new ContractVoter($permissions);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($admin), null, [ContractVoter::CREATE]),
        );
    }

    public function testCreateDeniedWhenPermissionFails(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $agent = $this->user(UserRoleEnum::AGENT);
        $permissions->expects(self::once())
            ->method('can')
            ->with($agent, 'contracts.create')
            ->willReturn(false);
        $voter = new ContractVoter($permissions);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($agent), null, [ContractVoter::CREATE]),
        );
    }

    public function testSignGrantedByPermissionForCustomer(): void
    {
        $customer = $this->user(UserRoleEnum::ADMIN);
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $permissions->expects(self::once())
            ->method('can')
            ->with($customer, 'contracts.sign')
            ->willReturn(true);
        $voter = new ContractVoter($permissions);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($customer), $this->contract(), [ContractVoter::SIGN]),
        );
    }

    public function testSignGrantedForSupplierPartyWithoutPermission(): void
    {
        // Исполнитель договора подписывает по принадлежности компании (subject),
        // даже без права contracts.sign (в каталоге supplier его нет, FR-1.4.3).
        $contract = $this->contract();
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $permissions->expects(self::once())
            ->method('can')
            ->willReturn(false);
        $voter = new ContractVoter($permissions);
        $supplierUser = $this->user(UserRoleEnum::ADMIN, $contract->getSupplierId());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($supplierUser), $contract, [ContractVoter::SIGN]),
        );
    }

    public function testSignDeniedForNonPartyWithoutPermission(): void
    {
        $permissions = self::createStub(PermissionCheckerInterface::class);
        $permissions->method('can')->willReturn(false);
        $voter = new ContractVoter($permissions);
        $outsider = $this->user(UserRoleEnum::ADMIN);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($outsider), $this->contract(), [ContractVoter::SIGN]),
        );
    }

    public function testSignWithWrongSubjectTypeAbstains(): void
    {
        // SIGN поддерживается только для subject=Contract; другой subject → abstain.
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($this->user(UserRoleEnum::ADMIN)), 'not-a-contract', [ContractVoter::SIGN]),
        );
    }

    public function testUnknownAttributeAbstains(): void
    {
        $token = $this->token($this->user(UserRoleEnum::ADMIN));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, null, ['SomeUnknownAction']));
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $this->contract(), ['SomeUnknownAction']),
        );
    }

    public function testNonAppUserTokenDenied(): void
    {
        $notAppUser = self::createStub(UserInterface::class);
        $token = new UsernamePasswordToken($notAppUser, 'api', []);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, [ContractVoter::CREATE]));
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $this->contract(), [ContractVoter::SIGN]),
        );
    }
}
