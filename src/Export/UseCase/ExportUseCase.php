<?php

declare(strict_types=1);

namespace App\Export\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Export.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (command/query). Публичный контракт: контроллеры/другие модули вызывают
 * UseCase напрямую; во внутренности модуля (Entity/Repository/Form/Input/
 * Presenter/Exception/Controller/...) не заглядывают (PHPArkitect, правило 6).
 *
 * Каждый UseCase: final class, implements ExportUseCase, один публичный метод
 * execute() со строгой типизацией входа/выхода.
 */
interface ExportUseCase
{
}
