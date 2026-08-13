<?php

declare(strict_types=1);

namespace App\Document\Exception;

/**
 * Ошибка файлового хранилища (чтение/запись/удаление файла).
 * Внутренняя ошибка — не API-исключение; DocumentService перехватывает и
 * бросает ValidationException/ConflictException при необходимости.
 */
final class StorageException extends \RuntimeException
{
}
