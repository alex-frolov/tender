<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Тип компании (FR-1.5.4): customer (заказчик), supplier (исполнитель), both.
 */
enum CompanyTypeEnum: string
{
    case CUSTOMER = 'customer';
    case SUPPLIER = 'supplier';
    case BOTH = 'both';

    public function isCustomer(): bool
    {
        return self::CUSTOMER === $this || self::BOTH === $this;
    }

    public function isSupplier(): bool
    {
        return self::SUPPLIER === $this || self::BOTH === $this;
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
