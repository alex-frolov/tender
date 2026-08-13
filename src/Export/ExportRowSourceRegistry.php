<?php

declare(strict_types=1);

namespace App\Export;

use App\Export\Entity\Enum\ExportTypeEnum;

/**
 * Реестр источников строк экспорта (UC-31, AM-15).
 *
 * Связывает тип экспорта (tenders/bids/contracts) с реализацией ExportRowSource
 * соответствующего модуля. Выбор — через supports(), чтобы реестр не знал
 * конкретные классы модулей (границы модулей, PHPArkitect rule 6).
 */
final readonly class ExportRowSourceRegistry
{
    /**
     * @param list<ExportRowSource> $sources
     */
    public function __construct(private array $sources)
    {
    }

    /**
     * @throws \LogicException если для типа экспорта нет источника
     */
    public function for(ExportTypeEnum $type): ExportRowSource
    {
        foreach ($this->sources as $source) {
            if ($source->supports($type)) {
                return $source;
            }
        }

        throw new \LogicException(\sprintf('No export row source for type "%s"', $type->value));
    }
}
