<?php

declare(strict_types=1);

namespace App\Tender\Exception;

use App\Shared\Exception\ApiException;
use App\Shared\Money\Enum\CurrencyEnum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инвариант суммы лотов нарушен (FR-1.1.7): при no_start_price=false
 * сумма price_net_minor всех лотов тендера должна равняться nmck_minor.
 * Проверяется при публикации и при изменении лотов.
 *
 * detail отдаётся в major units с кодом валюты («12 000.00 RUB»), а не
 * в копейках: текст уходит прямо в UI, и minor units там читаются как ошибка
 * на два порядка. Само сравнение по-прежнему целочисленное (PR-1).
 */
final class LotsSumMismatchException extends \RuntimeException implements ApiException
{
    public function __construct(
        private readonly int $lotsSumMinor,
        private readonly int $nmckMinor,
        private readonly string $currency,
    ) {
        parent::__construct(\sprintf(
            'Lots sum %s does not match NMCK %s',
            self::major($lotsSumMinor, $currency),
            self::major($nmckMinor, $currency),
        ));
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function getErrorCode(): string
    {
        return 'lots_sum_mismatch';
    }

    public function getTitle(): string
    {
        return 'Lots sum mismatch';
    }

    public function getLotsSumMinor(): int
    {
        return $this->lotsSumMinor;
    }

    public function getNmckMinor(): int
    {
        return $this->nmckMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * minor units → major units + код валюты (presentation, PR-1): без float,
     * масштаб берётся из CurrencyEnum (RUB — 2 знака, JPY — 0, BHD — 3).
     * Неизвестная валюта — масштаб 2 (дефолт реестра).
     */
    private static function major(int $amountMinor, string $currency): string
    {
        $exponent = CurrencyEnum::tryFrom($currency)?->exponent() ?? 2;
        $sign = $amountMinor < 0 ? '-' : '';
        $abs = abs($amountMinor);

        if (0 === $exponent) {
            return \sprintf('%s%d %s', $sign, $abs, $currency);
        }

        $divisor = 10 ** $exponent;

        return \sprintf(
            '%s%d.%s %s',
            $sign,
            intdiv($abs, $divisor),
            str_pad((string) ($abs % $divisor), $exponent, '0', \STR_PAD_LEFT),
            $currency,
        );
    }
}
