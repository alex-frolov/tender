<?php

declare(strict_types=1);

namespace App\Export;

use App\Export\Entity\Enum\ExportTypeEnum;
use Symfony\Component\Uid\Uuid;

/**
 * Источник строк для экспорта (UC-31, AM-15).
 *
 * Публичный read-контракт модуля: каждый бизнес-модуль (Tender/Bid/Contract)
 * реализует его в своём `src/{Module}/Service/` — там, где доступны собственные
 * Entity/Repository (границы модулей, PHPArkitect rule 6). Export-модуль
 * вызывает источник только через этот интерфейс.
 *
 * rows() возвращает итерируемую коллекцию строк (каждая строка — list<mixed>),
 * которая генерируется потоково (Doctrine getArrayResult / итераторы), чтобы
 * ExportJobProcessor не держал выборку в памяти (стриминг, NFR-18).
 */
interface ExportRowSource
{
    public function supports(ExportTypeEnum $type): bool;

    /**
     * Заголовки колонок (первая строка файла).
     *
     * @return list<string>
     */
    public function headers(): array;

    /**
     * Строки данных компании-тенанта по фильтрам.
     *
     * @param array<string, mixed> $filters фильтры выборки (status/from/to/…)
     *
     * @return iterable<list<bool|int|float|string|\DateTimeInterface|null>>
     */
    public function rows(Uuid $tenantId, array $filters): iterable;
}
