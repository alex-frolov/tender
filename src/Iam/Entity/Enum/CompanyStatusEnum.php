<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Статус верификации компании (FR-1.5.7):
 * pending → active (подтверждена суперадмином) / rejected / suspended.
 */
enum CompanyStatusEnum: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case SUSPENDED = 'suspended';

    public function isActive(): bool
    {
        return self::ACTIVE === $this;
    }

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
