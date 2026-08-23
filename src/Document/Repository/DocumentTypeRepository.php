<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Document\Entity\DocumentType;
use App\Shared\Exception\NotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий справочника типов документов (FR-1.2.7).
 *
 * Единая точка загрузки типа с 404-семантикой: findOrFail бросает
 * NotFoundException (→ 404 через JsonApiExceptionSubscriber). Нужен
 * контроллеру entity-bound update формы (PUT /document-types/{id}), которая
 * резолвит сущность ДО сабмита формы — см. AGENTS.md, «Entity-bound update формы».
 *
 * Идентификатор типа — bigint, а не UUID: из route приходит строка, поэтому
 * нечисловой/неположительный id — это 404, а не ошибка приведения типа в Doctrine.
 *
 * @extends ServiceEntityRepository<DocumentType>
 */
final class DocumentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentType::class);
    }

    /**
     * Тип документа по id из route или 404.
     *
     * @throws NotFoundException если id не число или тип не найден
     */
    public function findOrFail(string $documentTypeId): DocumentType
    {
        $id = (int) $documentTypeId;
        $type = $id > 0 ? $this->find($id) : null;
        if (null === $type) {
            throw new NotFoundException('Document type not found');
        }

        return $type;
    }
}
