<?php

declare(strict_types=1);

namespace App\SavedSearch\Entity\Enum;

/**
 * Периодичность автопоиска сохранённого поиска (F-A5, openapi
 * SavedSearchCreate.digest_period).
 *
 * - none — автопоиск выключен (просто сохранённый шаблон);
 * - daily — ежедневный дайджест по фильтрам поиска (автопоиск по расписанию);
 * - weekly — еженедельный дайджест по фильтрам поиска.
 *
 * Периодичность хранится в saved_searches.digest_period; фактическая рассылка
 * дайджестов — через модуль уведомлений (FR-1.6, NotificationDigestService).
 */
enum SavedSearchDigestPeriodEnum: string
{
    case NONE = 'none';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';

    /**
     * @return array<string, string> пары value => value для ChoiceType
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
