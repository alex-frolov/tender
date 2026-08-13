<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Переходы workflow company_verification (FR-1.5.7, domain/company-state-machine.md).
 * Значения соответствуют именам переходов в config/packages/workflow.yaml.
 */
enum CompanyStatusTransition: string
{
    case APPROVE = 'approve';
    case REJECT = 'reject';
    case SUSPEND = 'suspend';

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
