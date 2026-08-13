<?php

declare(strict_types=1);

namespace App\Notification;

use App\Iam\Entity\User;
use App\Notification\Repository\NotificationSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Обработчик мгновенной email-доставки уведомления (FR-1.6.2).
 *
 * Выполняется воркером транспорта `emails` (RabbitMQ, очередь tender_emails):
 * по NotificationEmailMessage строит письмо (подписка → пользователь → шаблон)
 * и отправляет через mailer. Доставка асинхронная (FR-1.6) — недоступный SMTP
 * не блокирует очередь доменных событий.
 *
 * Пропуски (без письма): подписка удалена/деактивирована, пользователь удалён
 * или без email — идемпотентная повторная доставка сообщения ничего не дублирует.
 */
#[AsMessageHandler]
final readonly class NotificationEmailMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationSubscriptionRepository $subscriptions,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $from,
    ) {
    }

    public function __invoke(NotificationEmailMessage $message): void
    {
        $subscription = $this->subscriptions->findById($message->subscriptionId);
        if (null === $subscription || !$subscription->isActive()) {
            $this->logger->info('Notification: subscription inactive or missing, email skipped', [
                'subscription_id' => $message->subscriptionId,
                'event_type' => $message->eventType,
            ]);

            return;
        }

        $user = $this->em->getRepository(User::class)->find($subscription->getUserId());
        if (null === $user || $user->isDeleted() || '' === $user->getEmail()) {
            $this->logger->info('Notification: user missing or deleted, email skipped', [
                'user_id' => (string) $subscription->getUserId(),
                'event_type' => $message->eventType,
            ]);

            return;
        }

        $locale = $user->getLocale()->value;
        $email = (new Email())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($this->translator->trans('subject', [], 'notification.subject', $locale))
            ->text($this->twig->render('email/notification.txt.twig', [
                'event_type' => $message->eventType,
                'event_id' => $message->eventId,
                'occurred_at' => $message->occurredAt,
                'payload' => $message->payload,
                'locale' => $locale,
            ]));

        $this->mailer->send($email);

        $this->logger->info('Notification email sent', [
            'subscription_id' => (string) $subscription->getId(),
            'user_id' => (string) $subscription->getUserId(),
            'event_type' => $message->eventType,
        ]);
    }
}
