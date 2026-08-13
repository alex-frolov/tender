<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Protocol;

use App\Tender\Entity\Tender;

/**
 * Формирование текстового содержимого протоколов плагина ru-state-procurement
 * (FR-1.2.8, «протоколы генерируются ядром из событий, оформляются плагином»).
 *
 * Контент — plain text (mime text/plain): документ прикладывается к тендеру
 * (entity_type=tender) с видимостью по типу (протокол вскрытия — публичный).
 * Деньги форматируются в major-единицы (PR-1: перевод в major — только
 * presentation, контент протокола таковым и является).
 */
final readonly class RuProtocolContentBuilder
{
    /**
     * Протокол вскрытия заявок (событие tender.opened).
     *
     * @param array<string, mixed> $payload ключевые поля tender.opened:
     *                                      number, bids_end, bids_count, opened_at
     */
    public function opening(Tender $tender, array $payload): string
    {
        $lines = [
            'ПРОТОКОЛ ВСКРЫТИЯ ЗАЯВОК',
            'Тендер: № '.$tender->getNumber().' «'.$tender->getTitle().'»',
            'Дата вскрытия: '.$this->str($payload, 'opened_at', '—'),
            'Дедлайн подачи заявок: '.$this->str($payload, 'bids_end', '—'),
            'Количество поданных заявок: '.$this->str($payload, 'bids_count', '—'),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Итоговый протокол (событие auction.winner_chosen).
     *
     * @param array<string, mixed> $payload ключевые поля auction.winner_chosen:
     *                                      auction_id, supplier_id, price_minor,
     *                                      basis, vat_rate (BPS), mode
     */
    public function final(Tender $tender, array $payload): string
    {
        $priceMinor = $this->int($payload, 'price_minor');
        $vatBps = $this->int($payload, 'vat_rate');

        $lines = [
            'ИТОГОВЫЙ ПРОТОКОЛ (ПОДВЕДЕНИЕ ИТОГОВ)',
            'Тендер: № '.$tender->getNumber().' «'.$tender->getTitle().'»',
            'Аукцион: '.$this->str($payload, 'auction_id', '—'),
            'Победитель (поставщик): '.$this->str($payload, 'supplier_id', '—'),
            'Цена контракта: '.$this->money($priceMinor).' ('.$this->str($payload, 'basis', '—').', НДС '.round($vatBps / 100, 2).'%)',
            'Режим выбора победителя: '.$this->str($payload, 'mode', '—'),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Форматирование minor units → major (PR-1, presentation): 123456 → "1 234,56".
     */
    private function money(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, ',', ' ').' ₽';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function str(array $payload, string $key, string $fallback): string
    {
        $value = $payload[$key] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : $fallback;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return \is_scalar($value) && is_numeric($value) ? (int) $value : 0;
    }
}
