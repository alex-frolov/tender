<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Service\PlatformSettingsService;

/**
 * Доменный часовой пояс платформы (FR-1.5.16, GET /platform/timezone).
 *
 * Query-use-case: возвращает сохранённую настройку или дефолт из env
 * DOMAIN_TIMEZONE. Доступ — любой аутентифицированный пользователь.
 */
final readonly class GetPlatformTimezoneUseCase implements PlatformUseCase
{
    public function __construct(private PlatformSettingsService $settings)
    {
    }

    /**
     * @return array{timezone_default: string}
     */
    public function execute(User $user): array
    {
        return ['timezone_default' => $this->settings->timezone()];
    }
}
