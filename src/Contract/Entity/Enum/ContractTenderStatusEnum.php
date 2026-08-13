<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Статус исполнения по тендеру (contract_tenders.status, FR-1.4.3/1.4.5, M4).
 *
 * Зеркалит исполнение по каждому тендеру многоразового/одноразового договора:
 * pending → in_work → done_by_performer → done; претензия — claim → done_by_claim
 * либо расторжение — terminated. Статус живёт в contract_tenders.status
 * (по тендеру), тогда как сам договор имеет свой жизненный цикл
 * (signed/registered/…, domain/contract-state-machine.md).
 *
 * Пары value => value для ChoiceType в формах (label == value).
 */
enum ContractTenderStatusEnum: string
{
    case PENDING = 'pending';
    case IN_WORK = 'in_work';
    case DONE_BY_PERFORMER = 'done_by_performer';
    case DONE = 'done';
    case CLAIM = 'claim';
    case DONE_BY_CLAIM = 'done_by_claim';
    case TERMINATED = 'terminated';

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
