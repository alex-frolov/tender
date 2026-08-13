<?php

declare(strict_types=1);

namespace App\Favorite\Input;

/**
 * Входные данные добавления записи в избранное (F-A6, openapi POST
 * /favorites).
 *
 * - entity_type — тип сущности (tender/lot);
 * - entity_id — id тендера или лота;
 * - note — необязательная заметка/метка (до 500 символов).
 *
 * Публичные nullable-поля (data_class формы FavoriteCreateType).
 */
final class AddFavoriteInput
{
    public string $entity_type = '';

    public string $entity_id = '';

    public ?string $note = null;
}
