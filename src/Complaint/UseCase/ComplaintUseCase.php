<?php

declare(strict_types=1);

namespace App\Complaint\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Complaint.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (command/query). Публичный контракт: другие модули и контроллеры вызывают
 * UseCase напрямую; во внутренности модуля (Entity/Repository/Form/Input/
 * Presenter/Exception/Controller/...) не заглядывают (PHPArkitect, правило 6).
 */
interface ComplaintUseCase
{
}
