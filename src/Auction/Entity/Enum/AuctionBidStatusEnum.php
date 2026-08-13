<?php

declare(strict_types=1);

namespace App\Auction\Entity\Enum;

/**
 * Статус ставки аукциона (data-model.md, auction_bids.status): accepted —
 * принята и учитывается; rejected — отклонена с причиной (reason). Запись
 * append-only: отклонённая ставка сохраняется в истории (аудит арифметики,
 * PR-9), но не влияет на current_price.
 */
enum AuctionBidStatusEnum: string
{
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';

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
