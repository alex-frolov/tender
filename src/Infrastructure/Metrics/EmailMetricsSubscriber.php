<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Mime\Email;

/**
 * Результат отправки почты на стороне консьюмера tender_emails.
 *
 * Все письма уходят асинхронно: MailerInterface::send() лишь кладёт
 * SendEmailMessage в очередь; SMTP выполняет worker. Поэтому успех/отказ
 * наблюдается по событиям messenger'а (worker-контейнеры), а не в месте
 * композиции письма: там успех означает только «задача встала в очередь».
 * sent — письмо ушло в SMTP без исключений; retried — временный сбой,
 * будет повтор по retry_strategy; failed — попытки исчерпаны (алерт
 * EmailSendFailures). Лейбл template — из заголовка X-Tender-Template.
 */
final readonly class EmailMetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(private EmailMetricsCollector $emailMetrics)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $email = $this->emailOf($event->getEnvelope()->getMessage());
        if (null === $email) {
            return;
        }

        $this->emailMetrics->sendFinished($this->templateOf($email), EmailMetricsCollector::OUTCOME_SENT);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $email = $this->emailOf($event->getEnvelope()->getMessage());
        if (null === $email) {
            return;
        }

        $outcome = $event->willRetry()
            ? EmailMetricsCollector::OUTCOME_RETRIED
            : EmailMetricsCollector::OUTCOME_FAILED;
        $this->emailMetrics->sendFinished($this->templateOf($email), $outcome);
    }

    /**
     * Письмо из конверта задачи: сам Email или обёртка SendEmailMessage.
     * Остальные сообщения шины (события, таймлайн, webhooks) игнорируются.
     */
    private function emailOf(object $message): ?Email
    {
        if ($message instanceof SendEmailMessage) {
            $message = $message->getMessage();
        }

        return $message instanceof Email ? $message : null;
    }

    /**
     * Имя шаблона для лейбла: заголовок X-Tender-Template, выставляемый в
     * месте композиции; без него — unknown.
     */
    private function templateOf(Email $email): string
    {
        $headers = $email->getHeaders();
        if (!$headers->has(EmailMetricsCollector::TEMPLATE_HEADER)) {
            return 'unknown';
        }

        $value = $headers->getHeaderBody(EmailMetricsCollector::TEMPLATE_HEADER);

        return \is_string($value) && '' !== $value ? $value : 'unknown';
    }
}
