<?php

declare(strict_types=1);

namespace App\Export\Entity\Enum;

/**
 * Статус фоновой задачи экспорта (UC-31, AM-15, openapi ExportJob.status).
 *
 * - queued — поставлена в очередь (POST /exports, 202);
 * - processing — воркер приступил к генерации файла;
 * - ready — файл готов, доступен download_url (GET /exports/{id}/download);
 * - failed — ошибка генерации (error в ответе).
 */
enum ExportJobStatusEnum: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';

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
