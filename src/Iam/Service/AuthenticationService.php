<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\LoginFailureEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\RefreshToken;
use App\Iam\Entity\User;
use App\Infrastructure\Metrics\AuthMetricsCollector;
use App\Shared\Audit\AuditService;
use App\Shared\Totp\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Аутентификация (FR-1.5.3): пароль + опционально TOTP → access+refresh.
 *
 * Правила:
 * - пользователь должен существовать, не быть удалённым/заблокированным;
 * - email должен быть подтверждён (FR-1.5.5);
 * - при включённой 2FA код TOTP обязателен;
 * - при успехе: last_login_at обновляется, refresh-токен сохраняется.
 *
 * Наблюдаемость: исход каждой попытки пишется в
 * auth_logins_total{outcome}. Наружу причин всего две (LoginFailureEnum):
 * всё до проверки пароля и сам неверный пароль — единый 401
 * invalid_credentials (не раскрываем существование и состояние аккаунта);
 * верный пароль без кода или с неверным кодом при включённой 2FA — 401
 * two_factor_required, иначе клиент не узнает, что нужно спросить TOTP.
 */
final readonly class AuthenticationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private JwtService $jwt,
        private RefreshTokenService $refreshTokens,
        private TotpService $totp,
        private AuditService $audit,
        private AuthMetricsCollector $authMetrics,
    ) {
    }

    /**
     * Проверка учётных данных. Возвращает пользователя или причину отказа
     * (см. LoginFailureEnum — какие причины различимы снаружи и почему).
     */
    public function authenticate(string $email, string $password, ?string $totpCode = null): User|LoginFailureEnum
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_UNKNOWN_USER);

            return LoginFailureEnum::INVALID_CREDENTIALS;
        }

        // удалённый пользователь не может логиниться (email замаскирован — find по email всё равно не сработает)
        if ($user->isDeleted() || UserStatusEnum::BLOCKED === $user->getVerificationStatus()) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_BLOCKED);

            return LoginFailureEnum::INVALID_CREDENTIALS;
        }

        // email не подтверждён (FR-1.5.5): invited/email_pending — действия запрещены
        if (null === $user->getEmailVerifiedAt()
            || UserStatusEnum::INVITED === $user->getVerificationStatus()
            || UserStatusEnum::EMAIL_PENDING === $user->getVerificationStatus()) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_UNVERIFIED);

            return LoginFailureEnum::INVALID_CREDENTIALS;
        }

        if (null === $user->getPasswordHash()
            || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_BAD_CREDENTIALS);

            return LoginFailureEnum::INVALID_CREDENTIALS;
        }

        if ($user->isTwoFactorEnabled()) {
            $secret = $user->getTotpSecret();
            if (null === $secret || null === $totpCode || !$this->totp->verify($secret, $totpCode)) {
                $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_2FA_REQUIRED);

                return LoginFailureEnum::TWO_FACTOR_REQUIRED;
            }
        }

        return $user;
    }

    /**
     * Выдача токенов после успешной аутентификации.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function issueTokens(User $user, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_SUCCESS);
        $user->markLastLogin();

        $access = $this->jwt->issue(
            $user->getId(),
            $user->getCompanyId(),
            $user->getRole()->value,
        );

        $refresh = $this->refreshTokens->issue($user, $ip, $userAgent);

        $this->em->flush();

        $this->audit->record(
            action: 'auth.login',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['two_factor' => $user->isTwoFactorEnabled()],
            ip: $ip,
        );

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refresh['token'],
            'expires_in' => $access['expires_in'],
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Ротация refresh-токена (запрос /auth/refresh).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     *
     * @throws \RuntimeException если токен недействителен/отозван/истёк
     */
    public function rotate(string $refreshToken, ?string $ip = null, ?string $userAgent = null): array
    {
        $old = $this->refreshTokens->findActive($refreshToken);
        if (null === $old) {
            throw new \RuntimeException('Invalid refresh token');
        }

        $new = $this->refreshTokens->rotate($old, $ip, $userAgent);
        $this->em->flush();

        $userId = $new['entity']->getUserId();
        $this->audit->record(
            action: 'auth.refresh',
            entityType: 'user',
            entityId: (string) $userId,
            actorType: 'user',
            actorId: (string) $userId,
            ip: $ip,
        );

        return [
            'access_token' => $this->jwt->issue(
                $userId,
                $this->companyIdOf($new['entity']),
                $this->roleOf($new['entity']),
            )['token'],
            'refresh_token' => $new['token'],
            'expires_in' => $this->jwt->accessTtl(),
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Отзыв refresh-токена (запрос /auth/logout).
     */
    public function logout(string $refreshToken): void
    {
        $old = $this->refreshTokens->findActive($refreshToken);
        if (null === $old) {
            return; // идемпотентность: повторный logout не ошибка
        }

        $this->refreshTokens->revoke($old);
        $this->em->flush();

        $userId = $old->getUserId();
        $this->audit->record(
            action: 'auth.logout',
            entityType: 'user',
            entityId: (string) $userId,
            actorType: 'user',
            actorId: (string) $userId,
        );
    }

    private function companyIdOf(RefreshToken $token): ?\Symfony\Component\Uid\Uuid
    {
        $user = $this->em->getRepository(User::class)->find($token->getUserId());

        return $user?->getCompanyId();
    }

    private function tenantIdOf(User $user): ?string
    {
        return null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null;
    }

    private function roleOf(RefreshToken $token): string
    {
        $user = $this->em->getRepository(User::class)->find($token->getUserId());

        return $user?->getRole()->value ?? UserRoleEnum::AGENT->value;
    }
}
