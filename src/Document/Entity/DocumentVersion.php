<?php

declare(strict_types=1);

namespace App\Document\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Версия документа (AM-8, FR-1.1.5). Каждая загрузка файла — новая версия:
 * свой file_id (id), номер version, SHA-256, размер, mime, оригинальное имя
 * и путь в хранилище. Хранит только метаданные; бинарное содержимое — в
 * файловом хранилище (FileStorage, path).
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_versions')]
#[ORM\Index(name: 'idx_document_versions_document', columns: ['document_id'])]
class DocumentVersion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false)]
    private Document $document;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(type: 'bigint')]
    private int $sizeBytes;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column(length: 500)]
    private string $originalName;

    #[ORM\Column(length: 500)]
    private string $storagePath;

    #[ORM\Column(type: 'uuid')]
    private Uuid $uploadedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        Document $document,
        int $version,
        string $sha256,
        int $sizeBytes,
        string $mimeType,
        string $originalName,
        string $storagePath,
        Uuid $uploadedBy,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->version = $version;
        $this->sha256 = $sha256;
        $this->sizeBytes = $sizeBytes;
        $this->mimeType = $mimeType;
        $this->originalName = $originalName;
        $this->storagePath = $storagePath;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSizeBytes(): int
    {
        return (int) $this->sizeBytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getUploadedBy(): Uuid
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
