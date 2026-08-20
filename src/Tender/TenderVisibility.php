<?php

declare(strict_types=1);

namespace App\Tender;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт видимости тендеров (FR-1.1.1, FR-1.5.14) — единое правило
 * «что компания вообще имеет право увидеть»:
 *   - тендер своей компании (в любом статусе, включая черновики);
 *   - чужой опубликованный (status <> draft) открытый тендер (access_type = open);
 *   - чужой опубликованный закрытый тендер (access_type = contract_holders),
 *     если у заказчика с компанией зрителя есть действующий (многоразовый)
 *     multi_use-договор.
 *
 * Правило одно на весь бэкенд: каталог (GET /tenders), карточка
 * (GET /tenders/{id}, 404 для невидимого) и производные списки других модулей
 * (Auction: GET /auctions, состояние аукциона) спрашивают только этот контракт,
 * а не собирают условие заново. Видимость НЕ даёт права участия: подача заявки
 * в закрытый тендер по-прежнему проверяется ContractAccessChecker (409
 * contract_required), а live-торги — R7 (AuctionStreamVoter).
 *
 * Реализация — App\Tender\Service\TenderVisibilityService.
 */
interface TenderVisibility
{
    /**
     * Параметры условия видимости для компании (один запрос к договорам).
     * Используется read-моделями со своим SQL (каталог тендеров).
     */
    public function scopeFor(Uuid $companyId): TenderVisibilityScope;

    /**
     * Виден ли компании конкретный тендер (карточка, доступ к аукциону).
     * false — тендера нет или он невидим (вызывающий отдаёт 404/403).
     */
    public function isVisible(Uuid $tenderId, Uuid $companyId): bool;

    /**
     * Фильтр набора тендеров по видимости — одним запросом на весь набор
     * (списки других модулей, где N+1 недопустим).
     *
     * @param list<Uuid> $tenderIds
     *
     * @return list<Uuid> видимые id (порядок не гарантируется)
     */
    public function filterVisible(array $tenderIds, Uuid $companyId): array;
}
