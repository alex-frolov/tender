<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Protocol;

/**
 * Типы авто-генерируемых протоколов плагина ru-state-procurement (FR-1.2.8).
 * Коды = коды auto_generated document_types (регистрируются через
 * App\Document\DocumentGenerator::ensureDocumentType).
 *
 * - OPENING — протокол вскрытия заявок (генерируется по событию tender.opened);
 * - FINAL — итоговый протокол (подведение итогов, по auction.winner_chosen).
 */
enum ProtocolType: string
{
    case OPENING = 'protocol_opening';
    case FINAL = 'protocol_final';

    /**
     * Пары value => value для конфигураций/форм (label == value).
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
