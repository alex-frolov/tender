<?php

declare(strict_types=1);

namespace App\Favorite\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Favorite.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (command/query). Публичный контракт: другие модули и контроллеры вызывают
 * UseCase напрямую; во внутренности модуля (Entity/Repository/Form/Input/
 * Presenter/Exception/Controller/...) не заглядывают (PHPArkitect, правило 6).
 *
 * Каждый UseCase: final class, implements FavoriteUseCase, один публичный метод
 * execute() со строгой типизацией входа/выхода.
 */
interface FavoriteUseCase
{
}
