<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Каталог разрешений (FR-1.5.15, управляется суперадмином).
 * Таблица: permissions. code — уникальный (например, tender.create).
 */
#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
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

    #[ORM\Column(length: 20)]
    private string $group;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name, string $group, ?string $description = null)
    {
        $this->code = $code;
        $this->name = $name;
        $this->group = $group;
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

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
