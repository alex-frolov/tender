<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики аутентификации и 2FA.
 *
 * - auth_logins_total{outcome} — попытки входа по исходам: success |
 *   bad_credentials | unverified | 2fa_required | blocked | unknown_user.
 *   Поверх rate limit даёт картину подбора паролей: много bad_credentials
 *   с разных IP rate limit по IP не ловит. Исходы различаются ВНУТРИ
 *   AuthenticationService (наружу по-прежнему единый 401 invalid_credentials —
 *   контракт API не раскрывает причину);
 * - auth_2fa_total{outcome} — управление вторым фактором: setup | enabled |
 *   confirm_failed | disabled | disable_failed.
 */
final readonly class AuthMetricsCollector
{
    final public const string LOGIN_SUCCESS = 'success';
    final public const string LOGIN_BAD_CREDENTIALS = 'bad_credentials';
    final public const string LOGIN_UNVERIFIED = 'unverified';
    final public const string LOGIN_2FA_REQUIRED = '2fa_required';
    final public const string LOGIN_BLOCKED = 'blocked';
    final public const string LOGIN_UNKNOWN_USER = 'unknown_user';

    final public const string TFA_SETUP = 'setup';
    final public const string TFA_ENABLED = 'enabled';
    final public const string TFA_CONFIRM_FAILED = 'confirm_failed';
    final public const string TFA_DISABLED = 'disabled';
    final public const string TFA_DISABLE_FAILED = 'disable_failed';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Попытка входа с исходом. Значения ограничены константами LOGIN_*.
     *
     * @throws MetricsRegistrationException
     */
    public function loginAttempt(string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'auth_logins_total', 'Total login attempts by outcome.', ['outcome'])
            ->inc([$outcome]);
    }

    /**
     * Операция управления 2FA с исходом (константы TFA_*).
     *
     * @throws MetricsRegistrationException
     */
    public function twoFactorEvent(string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'auth_2fa_total', 'Total 2FA management operations by outcome.', ['outcome'])
            ->inc([$outcome]);
    }
}
