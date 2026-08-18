<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Company;
use App\Iam\Entity\User;
use App\Iam\Input\UpdateMeInput;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Профиль текущего пользователя (FR-1.5.8, GET/PATCH /users/me).
 *
 * - GET: возвращает пользователя и его компанию (null — если нет компании);
 * - PATCH: смена имени и/или пароля; смена пароля — только при верном
 *   current_password (422 иначе), при смене пароля revoke всех refresh-токенов.
 */
final readonly class ProfileService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private RefreshTokenService $refreshTokens,
        private AuditService $audit,
    ) {
    }

    /**
     * Текущий пользователь и его компания.
     *
     * @return array{user: User, company: Company|null}
     */
    public function me(User $user): array
    {
        $company = null;
        if (null !== $user->getCompanyId()) {
            $company = $this->em->getRepository(Company::class)->find($user->getCompanyId());
        }

        return ['user' => $user, 'company' => $company];
    }

    /**
     * Обновление профиля: имя и/или пароль.
     *
     * @throws ValidationException если смена пароля без верного current_password
     */
    public function update(User $user, UpdateMeInput $input, ?string $ip = null): User
    {
        if (null !== $input->name && $input->name !== $user->getName()) {
            $user->changeName($input->name);
        }

        if (null !== $input->newPassword) {
            if (null === $input->currentPassword
                || null === $user->getPasswordHash()
                || !$this->passwordHasher->isPasswordValid($user, $input->currentPassword)) {
                throw new ValidationException('Current password is incorrect');
            }
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $input->newPassword));
            $this->refreshTokens->revokeAllForUser($user->getId());
        }

        $this->em->flush();

        $this->audit->record(
            action: 'auth.profile.update',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null,
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['name_changed' => null !== $input->name, 'password_changed' => null !== $input->newPassword],
            ip: $ip,
        );

        return $user;
    }
}
