<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\SecurityService;
use App\Iam\Entity\User;

/**
 * Удержание обеспечения (нарушение, FR-1.4.1/1.4.2, POST /securities/{securityId}/forfeit).
 *
 * Только активное обеспечение; только заказчик — в SecurityService::forfeit.
 * Ответ — {id, status}.
 */
final readonly class ForfeitSecurityUseCase implements ContractUseCase
{
    public function __construct(private SecurityService $securities)
    {
    }

    /**
     * @return array{id: string, status: string}
     */
    public function execute(User $user, string $securityId, ?string $ip = null): array
    {
        $security = $this->securities->forfeit($user, $securityId, $ip);

        return [
            'id' => (string) $security->getId(),
            'status' => $security->getStatus()->value,
        ];
    }
}
