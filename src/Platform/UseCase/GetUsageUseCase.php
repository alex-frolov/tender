<?php

declare(strict_types=1);

namespace App\Platform\UseCase;

use App\Iam\Entity\User;
use App\Platform\Service\PlatformUsageService;
use App\Shared\Exception\ConflictException;

/**
 * Потребление лимитов тенанта (FR-1.5.15, GET /usage).
 *
 * Query-use-case: requests (audit_log по action), events (outbox_events),
 * webhooks (webhook_deliveries) за период — день (24ч) или месяц (30 дней).
 * Доступ: admin компании (IsGranted в контроллере).
 */
final readonly class GetUsageUseCase implements PlatformUseCase
{
    public function __construct(private PlatformUsageService $usage)
    {
    }

    /**
     * @return array{requests: array<string, int>, events: int, webhooks: int}
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, ?string $period = null): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->usage->usage($companyId, $period);
    }
}
