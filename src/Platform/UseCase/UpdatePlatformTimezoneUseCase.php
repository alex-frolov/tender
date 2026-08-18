<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Input\PlatformTimezoneUpdateInput;
use App\Platform\Service\PlatformSettingsService;

/**
 * Установка доменного часового пояса платформы (FR-1.5.16, PUT /platform/timezone).
 *
 * Только суперадмин (platform_admin) с правом platform.timezone.manage
 * (проверка — атрибуты IsGranted в контроллере). Валидация IANA — в сервисе.
 */
final readonly class UpdatePlatformTimezoneUseCase implements PlatformUseCase
{
    public function __construct(private PlatformSettingsService $settings)
    {
    }

    /**
     * @return array{timezone_default: string}
     */
    public function execute(User $user, PlatformTimezoneUpdateInput $input, ?string $ip = null): array
    {
        return [
            'timezone_default' => $this->settings->setTimezone(
                $input->timezoneDefault,
                (string) $user->getId(),
                $ip,
            ),
        ];
    }
}
