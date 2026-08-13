<?php

declare(strict_types=1);

namespace App\Bid\Entity\Enum;

/**
 * Статус заявки (data-model.md, AM-4): draft/submitted/withdrawn/admitted/
 * rejected/winning/lost. Секретность до вскрытия (FR-1.2.2) не зависит от
 * статуса — содержимое зашифровано до вскрытия, метаданные
 * (включая status) видны всегда.
 */
enum BidStatusEnum: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case WITHDRAWN = 'withdrawn';
    case ADMITTED = 'admitted';
    case REJECTED = 'rejected';
    case WINNING = 'winning';
    case LOST = 'lost';

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
