<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Пользователь (FR-1.5.1, FR-1.5.2).
 * Привязан к компании; роли: admin/manager/agent; platform_admin — системная.
 * Soft-delete с маскированием email (FR-1.5.9): email заменяется на
 * u_{uuid}@deleted.local — логин под удалённым email невозможен.
 * Реализует UserInterface, чтобы выступать принципалом в security-токене
 * (механизм доступа через #[IsGranted] и Voter'ы — см. AGENTS.md).
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'idx_users_company', columns: ['company_id'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $companyId = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 100, enumType: UserRoleEnum::class)]
    private UserRoleEnum $role;

    #[ORM\Column(type: 'string', length: 20, enumType: UserStatusEnum::class, options: ['default' => 'email_pending'])]
    private UserStatusEnum $verificationStatus = UserStatusEnum::EMAIL_PENDING;

    #[ORM\Column(type: 'string', length: 5, enumType: LocaleEnum::class, options: ['default' => 'ru'])]
    private LocaleEnum $locale = LocaleEnum::RU;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    private bool $twoFactorEnabled = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $maskedEmail = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $name,
        UserRoleEnum $role,
        ?Uuid $companyId = null,
        ?LocaleEnum $locale = null,
    ) {
        $this->id = Uuid::v4();
        $this->email = $email;
        $this->name = $name;
        $this->role = $role;
        $this->companyId = $companyId;
        $this->locale = $locale ?? LocaleEnum::RU;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompanyId(): ?Uuid
    {
        return $this->companyId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRole(): UserRoleEnum
    {
        return $this->role;
    }

    public function getLocale(): LocaleEnum
    {
        return $this->locale;
    }

    public function getVerificationStatus(): UserStatusEnum
    {
        return $this->verificationStatus;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : 'user';
    }

    /**
     * Роли для security-компонента: значение роли + ROLE_-форма.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [
            $this->role->value,
            'ROLE_'.strtoupper($this->role->value),
        ];
    }

    public function eraseCredentials(): void
    {
        // нет открытых секретов в сущности — нечего стирать
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getMaskedEmail(): ?string
    {
        return $this->maskedEmail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role->isPlatformAdmin();
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function changeRole(UserRoleEnum $role): void
    {
        $this->role = $role;
    }

    public function changeLocale(LocaleEnum $locale): void
    {
        $this->locale = $locale;
    }

    public function markEmailVerified(): void
    {
        $this->emailVerifiedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->verificationStatus = UserStatusEnum::ACTIVE;
    }

    public function setVerificationStatus(UserStatusEnum $status): void
    {
        $this->verificationStatus = $status;
    }

    public function markLastLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function enableTwoFactor(string $totpSecret): void
    {
        $this->twoFactorEnabled = true;
        $this->totpSecret = $totpSecret;
    }

    public function disableTwoFactor(): void
    {
        $this->twoFactorEnabled = false;
        $this->totpSecret = null;
    }

    /**
     * Soft-delete с маскированием email (FR-1.5.9).
     */
    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->maskedEmail = $this->email;
        $this->email = \sprintf('u_%s@deleted.local', (string) $this->id);
    }
}
