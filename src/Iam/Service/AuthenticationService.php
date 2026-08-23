<?php

declare(strict_types=1);

namespace App\Iam\Service;

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
 * - email должен быть подтверждён (FR-1.5.5) — иначе 403;
 * - при включённой 2FA код TOTP обязателен;
 * - при успехе: last_login_at обновляется, refresh-токен сохраняется.
 *
 * Наблюдаемость: исход каждой попытки пишется в
 * auth_logins_total{outcome}. Причина различается только В МЕТРИКЕ — наружу
 * по-прежнему единый 401 invalid_credentials (контракт API не раскрывает,
 * что именно не так).
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
     * Проверка учётных данных. Возвращает пользователя или null, если
     * email/пароль неверны (не раскрываем, что именно не так).
     */
    public function authenticate(string $email, string $password, ?string $totpCode = null): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_UNKNOWN_USER);

            return null;
        }

        // удалённый пользователь не может логиниться (email замаскирован — find по email всё равно не сработает)
        if ($user->isDeleted() || UserStatusEnum::BLOCKED === $user->getVerificationStatus()) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_BLOCKED);

            return null;
        }

        // email не подтверждён (FR-1.5.5): invited/email_pending — действия запрещены
        if (null === $user->getEmailVerifiedAt()
            || UserStatusEnum::INVITED === $user->getVerificationStatus()
            || UserStatusEnum::EMAIL_PENDING === $user->getVerificationStatus()) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_UNVERIFIED);

            return null;
        }

        if (null === $user->getPasswordHash()
            || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_BAD_CREDENTIALS);

            return null;
        }

        if ($user->isTwoFactorEnabled()) {
            $secret = $user->getTotpSecret();
            if (null === $secret || null === $totpCode || !$this->totp->verify($secret, $totpCode)) {
                $this->authMetrics->loginAttempt(AuthMetricsCollector::LOGIN_2FA_REQUIRED);

                return null;
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
