<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики отправки почты.
 *
 * Все письма уходят асинхронно: MailerInterface::send() кладёт
 * SendEmailMessage в очередь tender_emails (RabbitMQ), SMTP выполняет worker.
 * Глубина очереди видна RabbitMQ-экспортеру, а вот результат отправки — нет:
 * молчащий SMTP = регистрация «работает», но никто не может подтвердить email
 * и войти. Поэтому результат наблюдается на стороне консьюмера
 * (EmailMetricsSubscriber): sent — письмо ушло в SMTP, failed — попытки
 * исчерпаны, retried — временный сбой, будет повтор.
 *
 * Лейбл template берётся из заголовка письма X-Tender-Template, который
 * выставляется в месте композиции (verification/password_reset/invite/
 * bid_rejected/notification/notification_digest); без него — unknown.
 */
final readonly class EmailMetricsCollector
{
    final public const string OUTCOME_SENT = 'sent';
    final public const string OUTCOME_FAILED = 'failed';
    final public const string OUTCOME_RETRIED = 'retried';

    /** Заголовок письма с именем шаблона для лейбла template. */
    final public const string TEMPLATE_HEADER = 'X-Tender-Template';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Итог обработки письма консьюмером tender_emails. Значения outcome
     * ограничены константами OUTCOME_*; template — конечный список мест
     * композиции писем, кардинальность не растёт.
     *
     * @throws MetricsRegistrationException
     */
    public function sendFinished(string $template, string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'email_send_total', 'Total email sends by template and outcome.', ['template', 'outcome'])
            ->inc([$template, $outcome]);
    }
}
