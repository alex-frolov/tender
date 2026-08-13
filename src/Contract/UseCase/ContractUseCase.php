<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Contract.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (command/query). Публичный контракт: другие модули и контроллеры вызывают
 * UseCase напрямую; во внутренности модуля (Entity/Repository/Form/Input/
 * Presenter/Exception/Controller/...) не заглядывают (PHPArkitect, правило 6).
 *
 * Каждый UseCase: final class, implements ContractUseCase, один публичный метод
 * execute() со строгой типизацией входа/выхода. Вход — валидированный DTO
 * (Form/Input) + контекст (сущность, User, ip); выход — презентация
 * (Presenter), готовая к JSON.
 */
interface ContractUseCase
{
}
