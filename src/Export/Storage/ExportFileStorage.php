<?php

declare(strict_types=1);

namespace App\Export\Storage;

use App\Export\Exception\ExportJobNotFoundException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Файловое хранилище экспортных файлов (UC-31, AM-15).
 *
 * Базовая реализация — локальный диск в `var/exports` (export_dir).
 * Абстракция повторяет App\Document\Storage\FileStorage, но вынесена в модуль
 * Export (границы модулей: чужие Storage недоступны). Каждый файл кладётся в
 * поддиректорию yyyy/mm с уникальным именем export_id.format; возвращается
 * относительный путь storage_path (хранится в export_jobs).
 *
 * Потоковая запись: ExportJobProcessor открывает файл напрямую (OpenSpout
 * openToFile) — хранилище отдаёт абсолютный путь и занимается cleanup.
 */
final class ExportFileStorage
{
    public function __construct(
        private readonly string $rootDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function absolutePath(string $fileId, string $extension): string
    {
        $relative = $this->relativePath($fileId, $extension);
        $absolute = $this->rootDir.'/'.$relative;

        $this->filesystem->mkdir(\dirname($absolute));

        return $absolute;
    }

    public function read(string $storagePath): string
    {
        $absolute = $this->rootDir.'/'.$storagePath;
        if (!$this->filesystem->exists($absolute)) {
            throw new ExportJobNotFoundException('Stored export file not found');
        }

        $content = file_get_contents($absolute);
        if (false === $content) {
            throw new ExportJobNotFoundException('Failed to read stored export file');
        }

        return $content;
    }

    public function size(string $storagePath): int
    {
        $absolute = $this->rootDir.'/'.$storagePath;
        if (!$this->filesystem->exists($absolute)) {
            return 0;
        }

        $size = filesize($absolute);

        return false === $size ? 0 : $size;
    }

    public function delete(string $storagePath): void
    {
        $absolute = $this->rootDir.'/'.$storagePath;
        if ($this->filesystem->exists($absolute)) {
            $this->filesystem->remove($absolute);
        }
    }

    private function relativePath(string $fileId, string $extension): string
    {
        $ext = '' === $extension ? '' : '.'.ltrim($extension, '.');

        return date('Y/m').'/'.$fileId.$ext;
    }
}
