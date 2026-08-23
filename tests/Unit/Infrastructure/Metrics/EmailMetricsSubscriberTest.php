<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\EmailMetricsCollector;
use App\Infrastructure\Metrics\EmailMetricsSubscriber;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * EmailMetricsSubscriber: исход отправки наблюдается на консьюмере
 * tender_emails — handled → sent, failed с ретраем → retried, failed
 * без ретрая → failed. Сообщения не-письма игнорируются.
 */
final class EmailMetricsSubscriberTest extends TestCase
{
    private CollectorRegistry $registry;

    private EmailMetricsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->subscriber = new EmailMetricsSubscriber(new EmailMetricsCollector($this->registry));
    }

    public function testHandledEmailIsCountedAsSent(): void
    {
        $this->subscriber->onHandled($this->handledEvent($this->email('verification')));

        $body = $this->render();
        self::assertStringContainsString('email_send_total{template="verification",outcome="sent"} 1', $body);
    }

    public function testFailedWithoutRetryIsCountedAsFailed(): void
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new SendEmailMessage($this->email('invite'))),
            'emails',
            new \RuntimeException('SMTP down'),
        );
        $this->subscriber->onFailed($event);

        $body = $this->render();
        self::assertStringContainsString('email_send_total{template="invite",outcome="failed"} 1', $body);
    }

    public function testFailedWithRetryIsCountedAsRetried(): void
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new SendEmailMessage($this->email('password_reset'))),
            'emails',
            new \RuntimeException('Temporary SMTP error'),
        );
        $event->setForRetry();
        $this->subscriber->onFailed($event);

        $body = $this->render();
        self::assertStringContainsString('email_send_total{template="password_reset",outcome="retried"} 1', $body);
    }

    public function testMissingTemplateHeaderFallsBackToUnknown(): void
    {
        $this->subscriber->onHandled($this->handledEvent((new Email())->from('a@b.c')->to('d@e.f')->subject('s')->text('t')));

        $body = $this->render();
        self::assertStringContainsString('email_send_total{template="unknown",outcome="sent"} 1', $body);
    }

    public function testNonEmailMessagesAreIgnored(): void
    {
        $dummy = new class {};

        $this->subscriber->onHandled(new WorkerMessageHandledEvent(new Envelope($dummy), 'async'));
        $this->subscriber->onFailed(new WorkerMessageFailedEvent(new Envelope($dummy), 'async', new \RuntimeException()));

        self::assertSame([], $this->registry->getMetricFamilySamples(), 'Ни одной серии не должно быть создано');
    }

    /**
     * Письмо с заголовком X-Tender-Template (как в местах композиции).
     */
    private function email(string $template): Email
    {
        $email = (new Email())
            ->from('noreply@tender.local')
            ->to('user@example.com')
            ->subject('Subject')
            ->text('Body');
        $email->getHeaders()->addTextHeader(EmailMetricsCollector::TEMPLATE_HEADER, $template);

        return $email;
    }

    private function handledEvent(RawMessage $message): WorkerMessageHandledEvent
    {
        return new WorkerMessageHandledEvent(new Envelope(new SendEmailMessage($message)), 'emails');
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
