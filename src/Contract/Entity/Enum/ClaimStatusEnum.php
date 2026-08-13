<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Статус претензии (claims.status, FR-1.4.5, domain/auction-state-machine.md):
 * draft → submitted → resolved_rejected / resolved_accepted / cancelled.
 */
enum ClaimStatusEnum: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case RESOLVED_REJECTED = 'resolved_rejected';
    case RESOLVED_ACCEPTED = 'resolved_accepted';
    case CANCELLED = 'cancelled';

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
