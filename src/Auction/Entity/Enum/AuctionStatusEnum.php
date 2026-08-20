<?php

declare(strict_types=1);

namespace App\Auction\Entity\Enum;

/**
 * Статус аукциона (FR-1.3.7, domain/auction-state-machine.md): полная модель
 * из 17 статусов, из которых 16 хранимых и фиктивный CREATED (не перситится —
 * только в памяти до первого persist). Переходы — только через symfony/workflow
 * (config/workflow/auction.yaml); статус служит marking_store-
 * свойством workflow (property: status).
 *
 * Терминальные: DONE / DONE_BY_CLAIM / CANCELLED / EXPIRED / DELETED.
 * PAUSED — торги приостановлены (таймер заморожен), SCHEDULED — планирование
 * старта (scheduled_start_at), DELETED — мягкое удаление только из DRAFT.
 */
enum AuctionStatusEnum: string
{
    /** Фиктивный статус нового объекта ДО сохранения в БД (не перситится). */
    case CREATED = 'created';

    case DRAFT = 'draft';
    case AGREEMENT = 'agreement';
    case NEW = 'new';
    case SCHEDULED = 'scheduled';
    case TRADE = 'trade';
    case PAUSED = 'paused';
    case CHOICE = 'choice';
    case APPROVE = 'approve';
    case IN_WORK = 'in_work';
    case DONE_BY_PERFORMER = 'done_by_performer';
    case DONE = 'done';
    case CLAIM = 'claim';
    case DONE_BY_CLAIM = 'done_by_claim';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case DELETED = 'deleted';

    /**
     * Хранимые статусы (без фиктивного CREATED, который не перситится):
     * пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            if (self::CREATED === $case) {
                continue;
            }
            $values[$case->value] = $case->value;
        }

        return $values;
    }

    /**
     * Круг видимости аукциона в этом статусе (FR-1.5.14).
     *
     * Матрица:
     *   created, draft, agreement, new, scheduled,
     *   paused, choice, expired, deleted            — только заказчик;
     *   trade                                       — все, кому виден тендер;
     *   approve, in_work, done_by_performer, done,
     *   claim, done_by_claim, cancelled             — заказчик и исполнитель.
     *
     * Публична ровно фаза торгов: до неё идёт подготовка заказчика, после
     * APPROVE — исполнение по конкретному победителю. PAUSED и CHOICE наружу
     * закрыты намеренно: пауза и выбор победителя — решения заказчика.
     */
    public function visibilityLevel(): AuctionVisibilityLevelEnum
    {
        return match ($this) {
            self::CREATED, self::DRAFT, self::AGREEMENT, self::NEW, self::SCHEDULED,
            self::PAUSED, self::CHOICE, self::EXPIRED, self::DELETED => AuctionVisibilityLevelEnum::OWNER_ONLY,
            self::TRADE => AuctionVisibilityLevelEnum::TENDER_VIEWERS,
            self::APPROVE, self::IN_WORK, self::DONE_BY_PERFORMER, self::DONE,
            self::CLAIM, self::DONE_BY_CLAIM, self::CANCELLED => AuctionVisibilityLevelEnum::OWNER_AND_WINNER,
        };
    }

    /**
     * Статусы с заданным кругом видимости — для SQL-условий списков
     * (`status IN (...)`), где перечислять статусы руками нельзя: новый статус
     * обязан пройти через match выше, иначе PHP бросит UnhandledMatchError.
     *
     * @return list<string>
     */
    public static function valuesWithVisibility(AuctionVisibilityLevelEnum $level): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            if ($case->visibilityLevel() === $level) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    /**
     * Статусы, в которых ставки принимаются (FR-1.3.2): только TRADE.
     * В SCHEDULED/PAUSED и прочих — отклонение с причиной.
     */
    public function acceptsBids(): bool
    {
        return self::TRADE === $this;
    }

    /**
     * Терминальный статус (необратимый): из DONE / DONE_BY_CLAIM / CANCELLED /
     * EXPIRED / DELETED нет исходящих переходов (domain/auction-state-machine.md).
     */
    public function isTerminal(): bool
    {
        return \in_array($this, [
            self::DONE,
            self::DONE_BY_CLAIM,
            self::CANCELLED,
            self::EXPIRED,
            self::DELETED,
        ], true);
    }
}
