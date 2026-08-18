<?php

declare(strict_types=1);

namespace App\Platform\Input;

/**
 * Входные данные установки доменного часового пояса (FR-1.5.16,
 * PUT /platform/timezone). timezone_default — IANA-идентификатор (валидация
 * в форме: непустой; на корректность IANA — в PlatformSettingsService).
 */
final class PlatformTimezoneUpdateInput
{
    public string $timezoneDefault = '';
}
