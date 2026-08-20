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
     * Тендер по id БЕЗ tenant-фильтра и БЕЗ проверки видимости (публичный
     * lookup, FR-1.2.1). Годится только там, где вызывающий сам ограничивает
     * выдачу компанией актора (списки заявок). Для сценариев, где данные
     * тендера отдаются или пополняются по одному лишь id (вопросы, жалобы), —
     * resolveVisibleTender().
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     */
    public function resolveTender(string $tenderId): Tender;

    /**
     * Тендер по id с проверкой видимости для компании-зрителя (FR-1.5.14):
     * невидимый тендер неотличим от несуществующего — 404, как в
     * GET /tenders/{id}. Единое правило видимости — App\Tender\TenderVisibility.
     *
     * @throws \App\Shared\Exception\NotFoundException если тендер не найден
     *                                                 или невидим компании
     */
    public function resolveVisibleTender(string $tenderId, Uuid $viewerCompanyId): Tender;

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
     * Подписи тендера и лота по списку id лотов — для списков других модулей
     * (Auction: GET /auctions показывает номер/название тендера и лота вместо
     * UUID). Одним запросом на весь список: N+1 при рендере списка недопустим.
     *
     * @param list<string> $lotIds id лотов (невалидные и несуществующие пропускаются)
     *
     * @return array<string, TenderLotLabel> подписи, ключ — id лота
     */
    public function lotLabels(array $lotIds): array;

    /**
     * Принадлежность тендера компании (tenant-проверка): существует ли тендер
     * с id в компании-тенанте. Кросс-модульные проверки (Document, Contract)
     * делают через этот контракт, а не через TenderRepository/EM напрямую —
     * сущность тендера при этом не возвращается (границы модулей, rule 6).
     */
    public function belongsToCompany(Uuid $tenderId, Uuid $companyId): bool;
}
