<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\EmailVerificationToken;
use App\Iam\Entity\Enum\UserStatusTransition;
use App\Iam\Entity\User;
use App\Infrastructure\Metrics\EmailMetricsCollector;
use App\Infrastructure\Metrics\RateLimitMetricsCollector;
use App\Shared\Audit\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Подтверждение email (FR-1.5.5).
 *
 * - токен одноразовый (в БД — sha256-хеш), TTL — EMAIL_VERIFY_TTL;
 * - письмо уходит через Symfony Mailer (dev — Mailpit, тесты — null transport);
 *   шаблон письма — templates/email/, subject и текст — из переводов
 *   (translations/email/subject, translations/email/template), домен verification.*;
 * - повторная отправка (resend): cooldown через rate-limiter `email_send`
 *   (RL-1, 5 писем / 10 минут), при превышении — rate_limited с Retry-After;
 * - при новой выдаче предыдущие неиспользованные токены пользователя
 *   инвалидируются (действует только последний);
 * - верификация: single-use + TTL; после подтверждения пользователь ACTIVE.
 */
final readonly class EmailVerificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private AuditService $audit,
        #[Autowire(service: 'limiter.email_send')]
        private RateLimiterFactory $emailLimiter,
        private RateLimitMetricsCollector $rateLimitMetrics,
        private Environment $twig,
        private TranslatorInterface $translator,
        #[Autowire(service: 'state_machine.user_status')]
        private WorkflowInterface $userWorkflow,
        private int $tokenTtl,
        private string $verifyUrlTemplate,
        private string $from,
    ) {
    }

    /**
     * Выпуск токена + отправка письма с ссылкой подтверждения.
     * Вызывается при регистрации и при resend. Возвращает сырой токен.
     */
    public function issue(User $user, ?string $ip = null): string
    {
        $token = bin2hex(random_bytes(32));

        $this->invalidatePending($user);
        $entity = new EmailVerificationToken(
            userId: $user->getId(),
            tokenHash: $this->hash($token),
            expiresAt: $this->expiry(),
        );
        $this->em->persist($entity);
        $this->em->flush();

        $this->sendEmail($user, $token);

        $this->audit->record(
            action: 'auth.email.issue',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['token_ttl' => $this->tokenTtl],
            ip: $ip,
        );

        return $token;
    }

    /**
     * Верификация по токену (FR-1.5.5): одноразовый, с TTL.
     * Возвращает пользователя при успехе, null — токен неверный/истёкший/использованный.
     */
    public function verify(string $token): ?User
    {
        $entity = $this->em->getRepository(EmailVerificationToken::class)
            ->findOneBy(['tokenHash' => $this->hash($token)]);
        if (null === $entity || $entity->isUsed() || $entity->isExpired()) {
            return null;
        }

        $entity->markUsed();

        $user = $this->em->getRepository(User::class)->find($entity->getUserId());
        if (null === $user || $user->isDeleted()) {
            $this->em->flush();

            return null;
        }

        if (null === $user->getEmailVerifiedAt()) {
            // email_pending → active по workflow user_status; markEmailVerified
            // дополнительно фиксирует email_verified_at (маркировка не хранит дату).
            if ($this->userWorkflow->can($user, UserStatusTransition::VERIFY_EMAIL->value)) {
                $this->userWorkflow->apply($user, UserStatusTransition::VERIFY_EMAIL->value);
            }
            $user->markEmailVerified();
        }
        $this->em->flush();

        $this->audit->record(
            action: 'auth.email.verify',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
            after: ['email_verified' => true],
        );

        return $user;
    }

    /**
     * Повторная отправка письма подтверждения (cooldown).
     * Существование email не раскрывается: not_found возвращает 202 без письма.
     *
     * @return array{status: 'sent'|'not_found'|'already_verified'|'rate_limited', retry_after?: int, headers?: array<string, string>}
     */
    public function resend(string $email, ?string $ip = null): array
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user || $user->isDeleted()) {
            return ['status' => 'not_found'];
        }

        if (null !== $user->getEmailVerifiedAt()) {
            return ['status' => 'already_verified'];
        }

        $hit = $this->emailLimiter->create($email)->consume(1);
        if (!$hit->isAccepted()) {
            // Лимит email_send исчерпан (rate_limit_exceeded_total, §1).
            $this->rateLimitMetrics->exceeded('email_send');

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
     * Инвалидация предыдущих неиспользованных токенов пользователя
     * (действует только последний выданный — FR-1.5.5 «повторная отправка»).
     */
    private function invalidatePending(User $user): void
    {
        $this->em->createQueryBuilder()
            ->update(EmailVerificationToken::class, 't')
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
        $link = \sprintf($this->verifyUrlTemplate, urlencode($token));
        $locale = $user->getLocale()->value;
        $email = (new Email())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($this->translator->trans('subject', [], 'verification.subject', $locale))
            ->text($this->twig->render('email/verification.txt.twig', [
                'link' => $link,
                'minutes' => intdiv($this->tokenTtl, 60),
                'locale' => $locale,
            ]));

        // Лейбл template для email_send_total (наблюдение SMTP на консьюмере).
        $email->getHeaders()->addTextHeader(EmailMetricsCollector::TEMPLATE_HEADER, 'verification');

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
