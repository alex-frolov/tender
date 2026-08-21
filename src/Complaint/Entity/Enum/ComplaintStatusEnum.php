<?php

declare(strict_types=1);

namespace App\Complaint\Entity\Enum;

/**
 * Статус жалобы по тендеру (complaints.status, FR-1.2.10).
 */
enum ComplaintStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case RESOLVED = 'resolved';

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
