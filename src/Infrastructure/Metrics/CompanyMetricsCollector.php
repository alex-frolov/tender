<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики очереди подтверждения компаний.
 *
 * companies_pending_verification — SLA суперадмина на UC-38: без метрики
 * компания может висеть в pending неделю, и это никак не видно. Значение
 * пересчитывается в GaugeMetricsUpdater (кэш 15 c).
 */
final readonly class CompanyMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Число компаний в очереди верификации (verification_status = pending).
     *
     * @throws MetricsRegistrationException
     */
    public function setPendingVerification(int $count): void
    {
        $this->registry
            ->getOrRegisterGauge('', 'companies_pending_verification', 'Number of companies waiting for superadmin verification.')
            ->set($count);
    }
}
