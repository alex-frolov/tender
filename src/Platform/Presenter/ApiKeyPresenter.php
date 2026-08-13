<?php

declare(strict_types=1);

namespace App\Platform\Presenter;

use App\Platform\Entity\ApiKey;

/**
 * Публичное представление API-ключей (openapi schema ApiKey, FR-1.5.13).
 *
 * raw-токен и token_hash в обычные представления НЕ включаются (AR-3): raw
 * отдаётся один раз при создании/ротации (withToken). last_used_at/revoked_at —
 * только для административного просмотра (list/get).
 */
final readonly class ApiKeyPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(ApiKey $key): array
    {
        return [
            'id' => (string) $key->getId(),
            'name' => $key->getName(),
            'scopes' => $key->getScopes(),
            'expires_at' => null !== $key->getExpiresAt()
                ? $key->getExpiresAt()->format('Y-m-d\TH:i:s\Z')
                : null,
            'last_used_at' => null !== $key->getLastUsedAt()
                ? $key->getLastUsedAt()->format('Y-m-d\TH:i:s\Z')
                : null,
            'revoked_at' => null !== $key->getRevokedAt()
                ? $key->getRevokedAt()->format('Y-m-d\TH:i:s\Z')
                : null,
            'created_at' => $key->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Представление с одноразовым raw-токеном (создание/ротация, AR-3).
     *
     * @return array<string, mixed>
     */
    public function withToken(ApiKey $key, string $token): array
    {
        $data = $this->single($key);
        $data['token'] = $token;

        return $data;
    }
}
