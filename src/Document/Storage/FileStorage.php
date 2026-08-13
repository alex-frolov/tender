<?php

declare(strict_types=1);

namespace App\Document\Storage;

use App\Document\Exception\StorageException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Файловое хранилище документов (AM-8, FR-1.1.5). Базовая реализация — локальный
 * диск (FILES_STORAGE=local, FILES_LOCAL_DIR). Абстракция позволяет заменить на
 * S3 подобно другим инфраструктурным слоям, не меняя DocumentService.
 *
 * Каждый файл кладётся в поддиректорию yyyy/mm с уникальным именем file_id.ext;
 * возвращается относительный путь storage_path (хранится в document_versions).
 */
final class FileStorage
{
    public function __construct(
        private readonly string $rootDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function store(string $content, string $fileId, string $extension): string
    {
        $relative = $this->relativePath($fileId, $extension);
        $absolute = $this->rootDir.'/'.$relative;

        try {
            $this->filesystem->dumpFile($absolute, $content);
        } catch (\Throwable $e) {
            throw new StorageException('Failed to store document file', 0, $e);
        }

        return $relative;
    }

    public function read(string $storagePath): string
    {
        $absolute = $this->rootDir.'/'.$storagePath;
        if (!$this->filesystem->exists($absolute)) {
            throw new StorageException('Stored document file not found');
        }

        $content = file_get_contents($absolute);
        if (false === $content) {
            throw new StorageException('Failed to read stored document file');
        }

        return $content;
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
