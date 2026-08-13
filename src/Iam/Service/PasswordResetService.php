<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\PasswordResetToken;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Восстановление пароля (FR-1.5.6).
 *
 * - `forgot`: выпуск одноразового токена + письмо с ссылкой сброса;
 *   существование email не раскрывается (202 в любом случае, письмо — только
 *   для реального пользователя); cooldown через rate-limiter `email_send`
 *   (RL-1, 5 писем / 10 минут), при превышении — rate_limited;
 * - `reset`: токен одноразовый + TTL (PASSWORD_RESET_TTL); после смены пароля
 *   все refresh-токены пользователя отзываются (открытые сессии инвалидируются);
 * - в БД хранится только sha256-хеш токена.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UserPasswordHasherInterface $passwordHasher,
        private RefreshTokenService $refreshTokens,
        private AuditService $audit,
        #[Autowire(service: 'limiter.email_send')]
        private RateLimiterFactory $emailLimiter,
        private Environment $twig,
        private TranslatorInterface $translator,
        private int $tokenTtl,
        private string $resetUrlTemplate,
        private string $from,
    ) {
    }

    /**
     * Запрос восстановления пароля (FR-1.5.6, первый шаг).
     * Существование email не раскрывается: not_found возвращает 202 без письма.
     *
     * @return array{status: 'sent'|'not_found'|'rate_limited', retry_after?: int, headers?: array<string, string>}
     */
    public function forgot(string $email, ?string $ip = null): array
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user || $user->isDeleted()) {
            return ['status' => 'not_found'];
        }

        $hit = $this->emailLimiter->create($email)->consume(1);
        if (!$hit->isAccepted()) {
            return [
                'status' => 'rate_limited',
                'retry_after' => max(0, $hit->getRetryAfter()->getTimestamp() - time()),
                'headers' => $this->rateLimitHeaders($hit),
            ];
        }

        $this->issue($user, $ip);

        return ['status' => 'sent'];
    }

    /**
     * Сброс пароля по одноразовому токену (FR-1.5.6, второй шаг).
     * Возвращает пользователя при успехе, null — токен неверный/истёкший/использованный.
     */
    public function reset(string $token, string $newPassword): ?User
    {
        $entity = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['tokenHash' => $this->hash($token)]);
        if (null === $entity || $entity->isUsed() || $entity->isExpired()) {
            return null;
        }

        $user = $this->em->getRepository(User::class)->find($entity->getUserId());
        if (null === $user || $user->isDeleted()) {
            return null;
        }

        // одноразовость: токен использован даже если смена пароля далее невозможна
        $entity->markUsed();

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $this->refreshTokens->revokeAllForUser($user->getId());
        $this->em->flush();

        $this->audit->record(
            action: 'auth.password.reset',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['password_reset' => true, 'sessions_revoked' => true],
            ip: null,
        );

        return $user;
    }

    /**
     * Выпуск токена + отправка письма с ссылкой сброса.
     */
    private function issue(User $user, ?string $ip = null): void
    {
        $token = bin2hex(random_bytes(32));

        $this->invalidatePending($user);
        $entity = new PasswordResetToken(
            userId: $user->getId(),
            tokenHash: $this->hash($token),
            expiresAt: $this->expiry(),
        );
        $this->em->persist($entity);
        $this->em->flush();

        $this->sendEmail($user, $token);

        $this->audit->record(
            action: 'auth.password.forgot',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['token_ttl' => $this->tokenTtl],
            ip: $ip,
        );
    }

    /**
     * Инвалидация предыдущих неиспользованных токенов пользователя
     * (действует только последний выданный — FR-1.5.6).
     */
    private function invalidatePending(User $user): void
    {
        $this->em->createQueryBuilder()
            ->update(PasswordResetToken::class, 't')
            ->set('t.usedAt', ':now')
            ->where('t.userId = :userId')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', $this->clock->now())
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->execute();
    }

    private function expiry(): \DateTimeImmutable
    {
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', $this->tokenTtl));
        if (!$expiresAt instanceof \DateTimeImmutable) {
            throw new \LogicException('Cannot compute token expiry');
        }

        return $expiresAt;
    }

    private function sendEmail(User $user, string $token): void
    {
        $link = \sprintf($this->resetUrlTemplate, urlencode($token));
        $locale = $user->getLocale()->value;
        $email = (new Email())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($this->translator->trans('subject', [], 'reset.subject', $locale))
            ->text($this->twig->render('email/reset.txt.twig', [
                'link' => $link,
                'minutes' => intdiv($this->tokenTtl, 60),
                'locale' => $locale,
            ]));

        $this->mailer->send($email);
    }

    /**
     * @return array<string, string>
     */
    private function rateLimitHeaders(RateLimit $hit): array
    {
        $retryAfter = max(0, $hit->getRetryAfter()->getTimestamp() - time());

        return [
            'X-RateLimit-Limit' => (string) $hit->getLimit(),
            'X-RateLimit-Remaining' => (string) $hit->getRemainingTokens(),
            'X-RateLimit-Reset' => (string) $hit->getRetryAfter()->getTimestamp(),
            'Retry-After' => (string) $retryAfter,
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function tenantIdOf(User $user): ?string
    {
        return null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null;
    }
}
