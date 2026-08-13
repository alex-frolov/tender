<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Auction.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (command/query). Публичный контракт: другие модули и контроллеры вызывают
 * UseCase напрямую; во внутренности модуля (Entity/Repository/Form/Input/
 * Presenter/Exception/Controller/...) не заглядывают (PHPArkitect, правило 6).
 *
 * Каждый UseCase: final class, implements AuctionUseCase, один публичный метод
 * execute() со строгой типизацией входа/выхода. Вход — валидированный DTO
 * (Form/Input) + контекст (сущность, User, idempotencyKey, ip); выход —
 * презентация (Presenter), готовая к JSON. Маркер используется PHPArkitect
 * для whitelist-проверки публичных контрактов.
 */
interface AuctionUseCase
{
}
