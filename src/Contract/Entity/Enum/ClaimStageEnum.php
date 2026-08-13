<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Стадия, на которой выставлена претензия (claims.stage, FR-1.4.5):
 * approve / in_work / done_by_performer — соответствует статусу аукциона
 * на момент выставления (domain/auction-state-machine.md, T29/T33/T35).
 */
enum ClaimStageEnum: string
{
    case APPROVE = 'approve';
    case IN_WORK = 'in_work';
    case DONE_BY_PERFORMER = 'done_by_performer';

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
