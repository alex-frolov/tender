<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\SecurityService;
use App\Iam\Entity\User;

/**
 * Возврат обеспечения (FR-1.4.1/1.4.2, POST /securities/{securityId}/release).
 *
 * Только активное обеспечение; сторона (заказчик/исполнитель) — в
 * SecurityService::release. Ответ — {id, status}.
 */
final readonly class ReleaseSecurityUseCase implements ContractUseCase
{
    public function __construct(private SecurityService $securities)
    {
    }

    /**
     * @return array{id: string, status: string}
     */
    public function execute(User $user, string $securityId, ?string $ip = null): array
    {
        $security = $this->securities->release($user, $securityId, $ip);

        return [
            'id' => (string) $security->getId(),
            'status' => $security->getStatus()->value,
        ];
    }
}
