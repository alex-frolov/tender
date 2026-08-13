<?php

declare(strict_types=1);

namespace App\SavedSearch\Entity;

use App\SavedSearch\Entity\Enum\SavedSearchDigestPeriodEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранённый шаблон поиска (F-A5, UC-17, AM-12, openapi SavedSearch).
 *
 * Пользователь сохраняет набор фильтров поиска (поисковая выдача/доска
 * тендеров) под именем и опционально включает автопоиск по расписанию
 * (digest_period: daily/weekly) — периодический дайджест новых тендеров,
 * подходящих под фильтры.
 *
 * Принадлежит пользователю (user_id) и его компании-тенанту (tenant_id):
 * - name — человекочитаемое имя шаблона;
 * - filters — произвольный JSON-объект фильтров (как в запросе поиска);
 * - digest_period — периодичность автопоиска (none/daily/weekly, FR-1.6,
 *   рассылка дайджеста через модуль уведомлений);
 * - active — активен ли автопоиск (включение/выключение, по умолчанию true).
 *
 * Сами фильтры интерпретирует поисковый слой (поиск по доске тендеров);
 * сущность хранит только шаблон и периодичность.
 */
#[ORM\Entity]
#[ORM\Table(name: 'saved_searches')]
#[ORM\Index(name: 'idx_saved_searches_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_saved_searches_tenant_digest_active', columns: ['tenant_id', 'digest_period', 'active'])]
class SavedSearch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    /** @var array<string, mixed> фильтры поиска (F-A5) */
    #[ORM\Column(type: 'json')]
    private array $filters;

    #[ORM\Column(type: 'string', length: 10, enumType: SavedSearchDigestPeriodEnum::class)]
    private SavedSearchDigestPeriodEnum $digestPeriod;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        Uuid $userId,
        Uuid $tenantId,
        string $name,
        array $filters,
        SavedSearchDigestPeriodEnum $digestPeriod = SavedSearchDigestPeriodEnum::NONE,
        bool $active = true,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->name = $name;
        $this->filters = $filters;
        $this->digestPeriod = $digestPeriod;
        $this->active = $active;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getDigestPeriod(): SavedSearchDigestPeriodEnum
    {
        return $this->digestPeriod;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
