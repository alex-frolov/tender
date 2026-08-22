<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Exception\ValidationException;
use Symfony\Component\Uid\Uuid;

/**
 * OPAQUE keyset-курсор списков API (AR-6/NFR-22).
 *
 * Курсор — base64url(JSON {c: created_at ISO-8601, i: id UUID}); код контракта:
 * ?cursor= — значение строго из предыдущего ответа (next_cursor). Невалидная
 * строка → ValidationException (422). Формат единый для всех списков
 * (Tender, Bid, AuctionBid, Contract, WebhookDelivery, ProcurementPlan).
 */
final readonly class KeysetCursor
{
    private function __construct(
        public \DateTimeImmutable $createdAt,
        public Uuid $id,
    ) {
    }

    /**
     * Декодировать курсор (null/'' → null — первая страница).
     *
     * @throws ValidationException если строка не является валидным курсором
     */
    public static function decode(?string $cursor): ?self
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $json) {
            throw new ValidationException('invalid cursor');
        }

        $payload = json_decode($json, true);
        if (!\is_array($payload) || !isset($payload['c'], $payload['i'])
            || !\is_string($payload['c']) || !\is_string($payload['i'])) {
            throw new ValidationException('invalid cursor');
        }

        $createdAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $payload['c'],
            new \DateTimeZone('UTC'),
        );
        if (false === $createdAt || !Uuid::isValid($payload['i'])) {
            throw new ValidationException('invalid cursor');
        }

        return new self($createdAt, Uuid::fromString($payload['i']));
    }

    /**
     * Кодировать курсор по позиции (created_at, id) последней строки страницы.
     */
    public static function encode(\DateTimeImmutable $createdAt, Uuid|string $id): string
    {
        $payload = json_encode(
            ['c' => $createdAt->format('Y-m-d\TH:i:s\Z'), 'i' => (string) $id],
            \JSON_THROW_ON_ERROR,
        );

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Страница и next_cursor из выборки, запрошенной с limit+1 (AR-6).
     *
     * Пара к keyset-условию в SQL: запрос сам отбирает строки ЗА курсором и
     * ограничивает выдачу limit+1, а лишняя строка отвечает на единственный
     * оставшийся вопрос — есть ли следующая страница. В отличие от
     * sliceAfter() (см. ниже), в PHP не приезжает весь набор: цена запроса —
     * O(limit) строк и памяти независимо от размера списка.
     *
     * @template T
     *
     * @param list<T>                                              $rows  результат выборки с limit+1
     * @param int                                                  $limit размер страницы
     * @param callable(T): array{0: \DateTimeImmutable, 1: string} $keyOf ключ строки: [created_at, id]
     *
     * @return array{0: list<T>, 1: string|null} [страница, next_cursor]
     */
    public static function pageOf(array $rows, int $limit, callable $keyOf): array
    {
        $hasMore = \count($rows) > $limit;
        /** @var list<T> $page */
        $page = $hasMore ? \array_slice($rows, 0, $limit) : $rows;

        $next = null;
        if ($hasMore && [] !== $page) {
            [$createdAt, $id] = $keyOf($page[\count($page) - 1]);
            $next = self::encode($createdAt, $id);
        }

        return [$page, $next];
    }

    /**
     * In-memory keyset-срез отсортированного списка после курсора (AR-6).
     *
     * Для ограниченных бизнесом списков (заявки одного тендера, претензии,
     * обеспечения, жалобы, планы закупок) пагинация выполняется над уже
     * полученным списком: элементы до позиции курсора включительно
     * пропускаются, из оставшихся берётся страница limit. next_cursor — ключ
     * последнего элемента страницы, только если остались элементы.
     *
     * Приём применим ровно там, где набор ограничен: он тянет в PHP ВЕСЬ
     * список. Списки, растущие без потолка (история ставок аукциона, договоры
     * компании), переведены на SQL-keyset + pageOf() — ../docs/db-query-audit.md,
     * п. 6.
     *
     * Порядок списка задаёт вызывающий и обязан сообщить его в $direction:
     * «после курсора» для ASC-списка — ключ больше курсора, для DESC-списка —
     * меньше. Направление не выводится из данных: для DESC-списка сравнение
     * по ASC-правилу отобрало бы первый же элемент, и вторая страница
     * оказалась бы копией первой с тем же next_cursor (бесконечный цикл).
     *
     * @template T of object
     *
     * @param list<T>                                              $items     полный отсортированный список
     * @param callable(T): array{0: \DateTimeImmutable, 1: string} $keyOf     ключ элемента: [created_at, id]
     * @param CursorDirection                                      $direction порядок $items по (created_at, id)
     *
     * @return array{0: list<T>, 1: string|null} [страница, next_cursor]
     */
    public static function sliceAfter(
        array $items,
        ?string $cursor,
        int $limit,
        callable $keyOf,
        CursorDirection $direction = CursorDirection::ASC,
    ): array {
        $pos = self::decode($cursor);
        $start = 0;

        if (null !== $pos) {
            foreach ($items as $i => $item) {
                [$createdAt, $id] = $keyOf($item);
                $cmp = $createdAt <=> $pos->createdAt;
                if (0 === $cmp) {
                    $cmp = strcmp($id, (string) $pos->id) <=> 0;
                }
                // В DESC-списке «дальше по списку» = ключ МЕНЬШЕ курсора.
                if (CursorDirection::DESC === $direction) {
                    $cmp = -$cmp;
                }
                if ($cmp > 0) {
                    $start = $i;
                    break;
                }
                $start = $i + 1;
            }
        }

        /** @var list<T> $page */
        $page = \array_slice($items, $start, $limit);
        $next = null;
        if ([] !== $page && $start + \count($page) < \count($items)) {
            [$createdAt, $id] = $keyOf($page[\count($page) - 1]);
            $next = self::encode($createdAt, $id);
        }

        return [$page, $next];
    }
}
