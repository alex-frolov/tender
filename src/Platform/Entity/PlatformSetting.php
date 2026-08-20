<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Настройка платформы (platform_settings, FR-1.5.16).
 *
 * key/value-хранилище глобальных настроек платформы (доменный часовой пояс,
 * признаки фиче-флагов и т.п.). Значения — скалярные; время — в UTC.
 * Хранится без tenant-привязки (настройки платформы, не тенанта).
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_settings')]
class PlatformSetting
{
    #[ORM\Id]
    #[ORM\Column(length: 100, unique: true)]
    private string $key;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, ?string $value = null)
    {
        $this->key = $key;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
