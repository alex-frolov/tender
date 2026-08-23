<?php

declare(strict_types=1);

namespace App\Notification;

use App\Iam\Entity\User;
use App\Infrastructure\Metrics\EmailMetricsCollector;
use App\Notification\Entity\NotificationDigestItem;
use App\Notification\Repository\NotificationDigestItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Ежедневный дайджест уведомлений (FR-1.6).
 *
 * Рассылка накопленных событий (notification_digest_items, digest=true-подписки)
 * одним письмом на пользователя. Идемпотентно: отправленные события помечаются
 * sent_at; повторный запуск не дублирует письма. Пользователь без действующего
 * аккаунта/email — события помечаются отправленными (не копятся вечно).
 */
final readonly class NotificationDigestService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationDigestItemRepository $items,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $from,
    ) {
    }

    /**
     * Рассылка дайджестов всем пользователям с накопленными событиями.
     * Возвращает число отправленных писем.
     */
    public function sendDigests(): int
    {
        $sent = 0;
        foreach ($this->items->findPendingUserIds() as $userId) {
            if (!$this->sendForUser($userId)) {
                continue;
            }
            ++$sent;
        }

        return $sent;
    }

    /**
     * Дайджест одного пользователя: письмо с группировкой по типу события.
     * События помечаются sent_at независимо от результата (не копятся).
     */
    private function sendForUser(string $userId): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        $pending = $this->items->findPendingByUser(Uuid::fromString($userId));
        if ([] === $pending) {
            return false;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (null === $user || $user->isDeleted() || '' === $user->getEmail()) {
            $this->markSent($pending);
            $this->em->flush();
            $this->logger->info('Notification digest: user missing, items discarded', [
                'user_id' => $userId,
                'count' => \count($pending),
            ]);

            return false;
        }

        $locale = $user->getLocale()->value;
        $email = (new Email())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($this->translator->trans('subject', [], 'notification_digest.subject', $locale))
            ->text($this->twig->render('email/notification_digest.txt.twig', [
                'items' => $this->grouped($pending),
                'count' => \count($pending),
                'locale' => $locale,
            ]));

        // Лейбл template для email_send_total (наблюдение SMTP на консьюмере).
        $email->getHeaders()->addTextHeader(EmailMetricsCollector::TEMPLATE_HEADER, 'notification_digest');

        $this->mailer->send($email);
        $this->markSent($pending);
        $this->em->flush();

        $this->logger->info('Notification digest sent', [
            'user_id' => $userId,
            'count' => \count($pending),
        ]);

        return true;
    }

    /**
     * Группировка событий по типу для шаблона письма: type → list of payload.
     *
     * @param list<NotificationDigestItem> $items
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grouped(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->getEventType()][] = $item->getPayload();
        }

        return $grouped;
    }

    /**
     * @param list<NotificationDigestItem> $items
     */
    private function markSent(array $items): void
    {
        foreach ($items as $item) {
            $item->markSent();
        }
    }
}
