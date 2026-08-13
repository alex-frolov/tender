<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт модуля Tender (загрузка Tender/Lot для потребителей
 * других модулей). Другие модули (Bid, Auction, Contract, Security) НЕ ходят
 * в TenderRepository напрямую — только через этот интерфейс.
 * Реализация — App\Tender\Service\TenderReadService (внутри модуля Tender).
 */
interface TenderReadService
{
    /**
     * Тендер по id БЕЗ tenant-фильтра (публичный lookup, FR-1.2.1).
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     */
    public function resolveTender(string $tenderId): Tender;

    /**
     * Лот с проверкой принадлежности тендеру (или null, если лот не указан).
     *
     * @throws \App\Shared\Exception\ConflictException если лот невалиден или
     *                                                 не принадлежит тендеру
     */
    public function resolveLot(Uuid $tenderId, ?string $lotId): ?Lot;

    /**
     * Лот по id БЕЗ tenant-фильтра (публичный lookup, создание аукциона
     * POST /auctions). Принадлежность тендера компании проверяет вызывающий
     * (AuctionWriteService через belongsToCompany).
     *
     * @throws \App\Shared\Exception\NotFoundException если лот не найден
     */
    public function resolveLotById(string $lotId): Lot;

    /**
     * Принадлежность тендера компании (tenant-проверка): существует ли тендер
     * с id в компании-тенанте. Кросс-модульные проверки (Document, Contract)
     * делают через этот контракт, а не через TenderRepository/EM напрямую —
     * сущность тендера при этом не возвращается (границы модулей, rule 6).
     */
    public function belongsToCompany(Uuid $tenderId, Uuid $companyId): bool;
}
