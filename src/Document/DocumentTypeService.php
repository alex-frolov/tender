<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Entity\DocumentType;
use App\Document\Input\CreateDocumentTypeInput;
use App\Document\Input\UpdateDocumentTypeInput;
use App\Iam\Entity\User;

/**
 * Публичный контракт модуля Document: справочник типов документов
 * (FR-1.2.7, AM-8). Кросс-модульные вызовы (Contract при скане договора,
 * lookup contract_scan по коду) — только через этот интерфейс (границы
 * модулей, PHPArkitect rule 6). Реализация —
 * App\Document\Service\DocumentTypeService.
 */
interface DocumentTypeService
{
    /**
     * Активные типы документов (справочник, FR-1.2.7).
     *
     * @return list<DocumentType>
     */
    public function list(): array;

    /**
     * Тип документа по коду (публичный lookup для потребителей модуля — напр.
     * ContractScanService ищет contract_scan по справочнику). null — код не
     * найден или тип деактивирован (активность проверяет вызывающий).
     */
    public function findByCode(string $code): ?DocumentType;

    /**
     * Создание типа документа суперадмином (FR-1.2.7).
     *
     * @throws \App\Shared\Exception\ConflictException   если code уже занят
     * @throws \App\Shared\Exception\ValidationException если auto_generated не разрешён (ядро без плагина)
     */
    public function create(User $actor, CreateDocumentTypeInput $input, ?string $ip = null): DocumentType;

    /**
     * Изменение типа документа суперадмином (FR-1.2.7). null = не менять.
     *
     * @throws \App\Shared\Exception\NotFoundException если тип не найден
     */
    public function update(User $actor, string $documentTypeId, UpdateDocumentTypeInput $input, ?string $ip = null): DocumentType;

    /**
     * Деактивация типа документа суперадмином (FR-1.2.7). Существующие документы
     * не удаляются; тип скрывается из активного справочника.
     *
     * @throws \App\Shared\Exception\NotFoundException если тип не найден
     */
    public function deactivate(User $actor, string $documentTypeId, ?string $ip = null): DocumentType;
}
