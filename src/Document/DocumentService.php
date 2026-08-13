<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Entity\Document;
use App\Iam\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Публичный контракт модуля Document: загрузка файла → документ с версиями,
 * чтение метаданных и скачивание с проверкой видимости (AM-8, FR-1.1.5,
 * FR-1.2.6). Кросс-модульные вызовы (Contract при скане договора) — только
 * через этот интерфейс (границы модулей, PHPArkitect rule 6). Реализация —
 * App\Document\Service\DocumentService.
 */
interface DocumentService
{
    /**
     * Загрузка документа (AM-8, POST /documents).
     *
     * @throws \App\Shared\Exception\ConflictException   если актор без компании
     * @throws \App\Shared\Exception\ValidationException если тип/файл не проходят проверки (размер/mime)
     * @throws \App\Shared\Exception\NotFoundException   если тип документа не найден/неактивен
     * @throws \App\Shared\Exception\NotFoundException   если привязка к тендеру вне компании актора
     */
    public function upload(User $actor, UploadedFile $file, string $documentTypeId, string $entityType, string $entityId, ?string $visibility, ?string $scope, ?string $ip = null): Document;

    /**
     * Загрузка новой версии в существующий документ (FR-1.1.5, версионирование).
     *
     * @throws Exception\DocumentNotFoundException     если документ не в компании актора
     * @throws Exception\DocumentAccessDeniedException если актор не владелец документа
     */
    public function addVersion(User $actor, string $documentId, UploadedFile $file, ?string $ip = null): Document;

    /**
     * Метаданные документа (AM-8, GET /documents/{id}) с проверкой видимости
     * (FR-1.2.6). Результат presenter-а включает download_url.
     *
     * @throws Exception\DocumentNotFoundException     если документ не найден (в т.ч. чужой tenant)
     * @throws Exception\DocumentAccessDeniedException если нет прав по видимости
     */
    public function get(User $actor, string $documentId): Document;

    /**
     * Контент текущей версии (скачивание). Проверяет видимость как и get().
     *
     * @return array{content: string, mimeType: string, originalName: string}
     */
    public function download(User $actor, string $documentId): array;
}
