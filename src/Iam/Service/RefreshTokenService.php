<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\RefreshToken;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Refresh-токены (FR-1.5.3): ротация, отзыв, TTL.
 *
 * - токен — непрозрачная случайная строка, в БД хранится только sha256-хеш;
 * - ротация: каждый /auth/refresh отзывает старый и выдаёт новый;
 * - отзыв (logout): revoke текущего refresh-токена.
 */
final readonly class RefreshTokenService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private int $refreshTtl,
    ) {
    }

    /**
     * Создание refresh-токена для пользователя.
     *
     * @return array{token: string, entity: RefreshToken}
     */
    public function issue(User $user, ?string $ip = null, ?string $userAgent = null): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', $this->refreshTtl));
        \assert($expiresAt instanceof \DateTimeImmutable);

        $entity = new RefreshToken(
            userId: $user->getId(),
            tokenHash: $this->hash($token),
            expiresAt: $expiresAt,
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->em->persist($entity);

        return ['token' => $token, 'entity' => $entity];
    }

    /**
     * Поиск действующего refresh-токена по значению (хеш в БД).
     * Возвращает null: токен не найден / отозван / истёк.
     */
    public function findActive(string $token): ?RefreshToken
    {
        $entity = $this->em->getRepository(RefreshToken::class)->findOneBy([
            'tokenHash' => $this->hash($token),
        ]);

        if (null === $entity || $entity->isRevoked() || $entity->isExpired()) {
            return null;
        }

        return $entity;
    }

    /**
     * Ротация: отзыв старого, выпуск нового. Возвращает новый токен.
     *
     * @return array{token: string, entity: RefreshToken}
     */
    public function rotate(RefreshToken $old, ?string $ip = null, ?string $userAgent = null): array
    {
        $old->revoke();

        $user = $this->em->getRepository(User::class)->find($old->getUserId());
        if (null === $user) {
            throw new \RuntimeException('User not found');
        }

        return $this->issue($user, $ip, $userAgent);
    }

    public function revoke(RefreshToken $token): void
    {
        $token->revoke();
    }

    /**
     * Отзыв всех refresh-токенов пользователя (смена пароля, блокировка).
     */
    public function revokeAllForUser(Uuid $userId): void
    {
        $this->em->createQueryBuilder()
            ->update(RefreshToken::class, 'rt')
            ->set('rt.revokedAt', ':now')
            ->where('rt.userId = :userId')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', $this->clock->now())
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
