<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ошибка конфигурации плагина ru-state-procurement: файл правил не найден или
 * содержит некорректные значения. Классифицируется как 500 (Configuration),
 * т.к. это проблема развёртывания, а не пользовательского запроса.
 */
final class ProcurementConfigException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function getErrorCode(): string
    {
        return 'plugin_configuration';
    }

    public function getTitle(): string
    {
        return 'Plugin configuration error';
    }
}
