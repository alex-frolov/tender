<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Repository;

use App\Shared\Exception\ValidationException;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;
use PHPUnit\Framework\TestCase;

/**
 * AR-6: in-memory keyset-срез (KeysetCursor::sliceAfter).
 *
 * Ключевой инвариант — направление списка: «после курсора» для ASC означает
 * ключ больше курсора, для DESC — меньше. При неверном направлении вторая
 * страница DESC-списка повторяла бы первую с тем же next_cursor
 * (бесконечная пагинация), поэтому обход проверяется до конца списка.
 */
final class KeysetCursorTest extends TestCase
{
    /**
     * Список строк, отсортированный в заданном направлении.
     *
     * @return list<CursorRow>
     */
    private static function items(int $count, CursorDirection $direction): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = CursorRow::at($i);
        }

        if (CursorDirection::DESC === $direction) {
            $items = array_reverse($items);
        }

        return array_values($items);
    }

    /**
     * @return callable(CursorRow): array{0: \DateTimeImmutable, 1: string}
     */
    private static function keyOf(): callable
    {
        return static fn (CursorRow $row): array => [$row->createdAt, $row->id];
    }

    /**
     * @param list<CursorRow> $items
     *
     * @return list<string> id строк в порядке выдачи
     */
    private static function ids(array $items): array
    {
        return array_map(static fn (CursorRow $row): string => $row->id, $items);
    }

    /**
     * Пройти список страницами до конца и собрать порядок обхода.
     *
     * @param list<CursorRow> $items
     *
     * @return list<string> id в порядке выдачи
     */
    private static function walk(array $items, int $limit, CursorDirection $direction): array
    {
        $seen = [];
        $cursor = null;
        // Ограничитель: при поломанном направлении цикл иначе не закончится.
        for ($page = 0; $page < 10; ++$page) {
            [$rows, $cursor] = KeysetCursor::sliceAfter($items, $cursor, $limit, self::keyOf(), $direction);
            foreach ($rows as $row) {
                $seen[] = $row->id;
            }
            if (null === $cursor) {
                break;
            }
        }

        return $seen;
    }

    public function testAscPaginationWalksWholeListWithoutRepeats(): void
    {
        $items = self::items(5, CursorDirection::ASC);

        $seen = self::walk($items, 2, CursorDirection::ASC);

        self::assertSame(self::ids($items), $seen);
    }

    public function testDescPaginationWalksWholeListWithoutRepeats(): void
    {
        $items = self::items(5, CursorDirection::DESC);

        $seen = self::walk($items, 2, CursorDirection::DESC);

        self::assertSame(self::ids($items), $seen);
        self::assertSame($seen, array_values(array_unique($seen)));
    }

    public function testDescSecondPageDiffersFromFirst(): void
    {
        $items = self::items(5, CursorDirection::DESC);

        [$first, $cursor] = KeysetCursor::sliceAfter($items, null, 2, self::keyOf(), CursorDirection::DESC);
        self::assertIsString($cursor);

        [$second] = KeysetCursor::sliceAfter($items, $cursor, 2, self::keyOf(), CursorDirection::DESC);

        self::assertSame([$items[0]->id, $items[1]->id], self::ids($first));
        self::assertSame([$items[2]->id, $items[3]->id], self::ids($second));
    }

    public function testLastPageHasNoNextCursor(): void
    {
        $items = self::items(4, CursorDirection::DESC);

        [, $cursor] = KeysetCursor::sliceAfter($items, null, 4, self::keyOf(), CursorDirection::DESC);

        self::assertNull($cursor);
    }

    public function testInvalidCursorIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        KeysetCursor::sliceAfter(self::items(2, CursorDirection::ASC), 'not-a-cursor', 2, self::keyOf());
    }
}
