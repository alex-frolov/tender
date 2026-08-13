<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Tender: агрегация статуса тендера при мультилоте
 * (FR-1.1.3, вариант C «бутылочное горлышко»). Вызывается после любой мутации
 * статуса лота/аукциона, в т.ч. кросс-модульно (Contract при завершении
 * исполнения) — только через этот интерфейс, не трогая workflow напрямую
 * (границы модулей, PHPArkitect rule 6). Реализация —
 * App\Tender\Service\TenderStatusAggregator.
 */
interface TenderStatusAggregator
{
    /**
     * Пересчёт статуса тендера по id (FR-1.1.3): загружает Tender и вызывает
     * recalculate(). Публичный контракт для потребителей других модулей
     * (Contract), чтобы они не тащили объект Tender и не ходили в его
     * репозиторий напрямую.
     *
     * @param bool $flush выполнить flush после переходов (по умолчанию true)
     */
    public function recalculateById(Uuid $tenderId, bool $flush = true): void;

    /**
     * Пересчитать статус тендера по статусам лотов (FR-1.1.3, вариант C) и
     * продвинуть его по фазам. Идемпотентен и монотонен: если агрегированный
     * статус не «впереди» текущего — ничего не делает.
     *
     * @param bool $flush выполнить flush после переходов (по умолчанию true)
     */
    public function recalculate(Tender $tender, bool $flush = true): void;

    /**
     * Применить конкретный переход (например republish). Guard'ы workflow
     * проверяют допустимость перехода из текущего статуса.
     *
     * @throws \App\Shared\Exception\StateTransitionException если переход недопустим
     */
    public function applyTransition(Tender $tender, TenderStatusTransition $transition): void;
}
