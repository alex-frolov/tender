<?php

declare(strict_types=1);

namespace App\SavedSearch;

use App\SavedSearch\Entity\SavedSearch;

/**
 * Публичное представление сохранённого поиска (F-A5, openapi SavedSearch).
 */
final readonly class SavedSearchPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(SavedSearch $savedSearch): array
    {
        return [
            'id' => (string) $savedSearch->getId(),
            'name' => $savedSearch->getName(),
            'filters' => $savedSearch->getFilters(),
            'digest_period' => $savedSearch->getDigestPeriod()->value,
            'active' => $savedSearch->isActive(),
            'created_at' => $savedSearch->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
