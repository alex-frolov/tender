<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Money;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * PR-1: запрет float/double/decimal для денег.
 *
 * Сканирует src/Shared/Money на запрещённые сигнатуры в КОДЕ
 * (не в комментариях): float-типы, decimal, double. Это дополняет
 * PHPStan (strict_types) и phparkitect-правило.
 */
final class MoneyNoFloatTest extends TestCase
{
    public function testNoFloatInMoneyModule(): void
    {
        $finder = (new Finder())
            ->files()
            ->in(\dirname(__DIR__, 4).'/src/Shared/Money')
            ->name('*.php');

        $violations = [];
        foreach ($finder as $file) {
            $lines = explode("\n", (string) $file->getContents());
            foreach ($lines as $lineNo => $line) {
                $trimmed = trim($line);
                // пропускаем комментарии и пустые строки
                if ('' === $trimmed || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match('/\b(float|double|decimal)\b/i', $line)) {
                    $violations[] = \sprintf('%s:%d: %s', $file->getFilename(), $lineNo + 1, trim($line));
                }
            }
        }

        self::assertSame([], $violations, "Float/decimal в Money-модуле запрещён (PR-1):\n".implode("\n", $violations));
    }
}
