<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Entity\Document;
use App\Document\Entity\DocumentType;
use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentOwnerRole;
use App\Document\Entity\Enum\DocumentVisibility;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Document: авто-генерация документов от имени
 * системы (FR-1.2.8, PL-6 «Документы: авто-генерация»).
 *
 * Авто-генерация — ТОЛЬКО через плагин (policy-плагин, например
 * ru-state-procurement): плагин создаёт документы от имени системы (owner_role
 * = system, is_auto_generated = true) и прикладывает их к тендеру/договору с
 * указанной видимостью. В базовом ядре авто-генерации нет — методы вызываются
 * только реализациями плагинов через этот контракт.
 *
 * - ensureDocumentType() — идемпотентная регистрация auto_generated-типа
 *   документа (справочник document_types, FR-1.2.7). Админский API
 *   (DocumentTypeService::create) такие типы создать не может
 *   (auto_generated выставляется только плагином);
 * - generate() — создание авто-генерируемого документа (owner_role=system,
 *   is_auto_generated=true) + версии, с сохранением контента в FileStorage.
 *   Идемпотентно (при повторе для того же типа и сущности возвращает
 *   существующий документ — at-least-once доставка событий, NFR-5).
 *
 * Реализация — App\Document\Service\DocumentGeneratorService (внутри модуля).
 */
interface DocumentGenerator
{
    /**
     * Регистрация auto_generated-типа документа (идемпотентна): если тип с
     * кодом уже существует — возвращает его (не меняя); иначе создаёт тип с
     * owner_role=system, auto_generated=true.
     */
    public function ensureDocumentType(
        string $code,
        string $name,
        DocumentOwnerRole $ownerRole,
        DocumentVisibility $visibility,
        bool $required = false,
        int $sortOrder = 0,
    ): DocumentType;

    /**
     * Создание авто-генерируемого документа от имени системы (FR-1.2.8).
     *
     * Контент сохраняется в FileStorage (как при загрузке файла), в БД —
     * метаданные документа и версии (sha256, размер, mime). Повторный вызов
     * для той же (тип, entity_type, entity_id) возвращает существующий документ
     * без создания новой версии (идемпотентность, NFR-5).
     *
     * @param string                  $documentTypeCode код auto_generated-типа
     *                                                  (ensureDocumentType)
     * @param string                  $title            название документа
     * @param string                  $content          содержимое файла
     * @param string                  $mimeType         mime-тип контента
     * @param string                  $extension        расширение файла
     * @param Uuid                    $tenantId         компания-владелец
     * @param DocumentVisibility|null $visibility       переопределение видимости
     *                                                  (по умолчанию — из типа)
     *
     * @throws \App\Shared\Exception\ConflictException если тип не найден/не
     *                                                 auto_generated/неактивен
     * @throws Exception\StorageException              если контент не сохранён
     */
    public function generate(
        string $documentTypeCode,
        DocumentEntityType $entityType,
        Uuid $entityId,
        string $title,
        string $content,
        string $mimeType,
        string $extension,
        Uuid $tenantId,
        ?DocumentVisibility $visibility = null,
        ?string $ip = null,
    ): Document;
}
