<?php

declare(strict_types=1);

namespace App\Analytics\UseCase;

/**
 * Маркер-интерфейс UseCase модуля Analytics.
 *
 * UseCase — прикладной слой модуля: 1 класс = 1 действие пользователя
 * (query). Публичный контракт: контроллеры вызывают UseCase напрямую.
 * Каждый UseCase: final class, implements AnalyticsUseCase, один публичный
 * метод execute() со строгой типизацией.
 */
interface AnalyticsUseCase
{
}
