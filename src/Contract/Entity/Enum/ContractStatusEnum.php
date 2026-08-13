<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Статус договора (FR-1.4.3, domain/contract-state-machine.md, M5):
 * draft → pending_signature → signed → registered; терминальные terminated,
 * expired (по valid_to), deleted (мягкое удаление до начала исполнения).
 *
 * Переходы — только через symfony/workflow (config/workflow/contract.yaml).
 * Действующим для целей закрытых тендеров (FR-1.5.14) считается signed/registered.
 */
enum ContractStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_SIGNATURE = 'pending_signature';
    case SIGNED = 'signed';
    case REGISTERED = 'registered';
    case TERMINATED = 'terminated';
    case EXPIRED = 'expired';
    case DELETED = 'deleted';

    /**
     * Действующий договор (для проверки доступа contract_holders, FR-1.5.14):
     * подписан или зарегистрирован, не terminated/expired/deleted.
     */
    public function isActive(): bool
    {
        return self::SIGNED === $this || self::REGISTERED === $this;
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
