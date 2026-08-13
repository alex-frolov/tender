<?php

declare(strict_types=1);

namespace App\Favorite;

use App\Favorite\Entity\Favorite;

/**
 * Публичное представление записи избранного (F-A6, openapi Favorite).
 */
final readonly class FavoritePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Favorite $favorite): array
    {
        return [
            'id' => (string) $favorite->getId(),
            'entity_type' => $favorite->getEntityType()->value,
            'entity_id' => (string) $favorite->getEntityId(),
            'note' => $favorite->getNote(),
            'created_at' => $favorite->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
