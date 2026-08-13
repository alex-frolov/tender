<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Тип договора (справочник, FR-1.4.3). Стартовый набор: base.
 * Определяет scope (single_use/multi_use) по умолчанию.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract_types')]
class ContractType
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection */
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 20, options: ['default' => 'single_use'])]
    private string $defaultScope;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $code,
        string $name,
        string $defaultScope = 'single_use',
        ?string $description = null,
    ) {
        $this->code = $code;
        $this->name = $name;
        $this->defaultScope = $defaultScope;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefaultScope(): string
    {
        return $this->defaultScope;
    }

    public function getDescription(): ?string
    {
        return $this->description;
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
