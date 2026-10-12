<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * Реестр ErrorCode в public/openapi.yaml совпадает с кодами, которые API
 * реально отправляет.
 *
 * Клиенты разбирают ошибки по `code`, поэтому расхождение в любую сторону —
 * баг контракта: код из реестра, который никто не шлёт, клиент ждёт напрасно
 * (так было с two_factor_required — форма входа фронта не могла показать поле
 * TOTP), а код вне реестра клиент не умеет обработать. До 2026-10-11 реестр
 * разошёлся с кодом на 7 фантомных и 13 неописанных кодов.
 *
 * Коды собираются из исходников теми путями, какими их задаёт проект — других способов задать код
 * в проекте нет:
 *  - `getErrorCode()` исключения возвращает литерал;
 *  - код передаётся вторым аргументом конструктора исключения
 *    (BidRejectedException, AuctionWinnerException);
 *  - тело ответа собирается массивом с ключом `'code'` (auth-эндпоинты,
 *    ApiAccessDeniedHandler), для входа — из значений LoginFailureEnum.
 */
final class OpenApiErrorCodeRegistryTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testRegistryMatchesCodesSentByTheApi(): void
    {
        $registry = $this->registryCodes();
        $source = $this->sourceCodes();

        self::assertSame([], array_values(array_diff($source, $registry)), 'Коды из src, которых нет в реестре ErrorCode');
        self::assertSame([], array_values(array_diff($registry, $source)), 'Коды реестра ErrorCode, которые API не отправляет');
    }

    /**
     * @return list<string>
     */
    private function registryCodes(): array
    {
        $spec = file_get_contents(self::ROOT.'/public/openapi.yaml');
        self::assertIsString($spec);

        $start = strpos($spec, 'Error code registry');
        self::assertNotFalse($start, 'В спеке нет блока Error code registry');
        $block = substr($spec, $start);
        // Блок description заканчивается на следующем ключе схемы (8 пробелов отступа).
        $end = preg_match('/\n {8}\S/', $block, $match, \PREG_OFFSET_CAPTURE);
        if (1 === $end) {
            $block = substr($block, 0, $match[0][1]);
        }

        preg_match_all('/^\s+- ([a-z_0-9]+) \(\d{3}\)/m', $block, $matches);

        return $this->normalize($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function sourceCodes(): array
    {
        $codes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $php = file_get_contents($file->getPathname());
            self::assertIsString($php);

            if (preg_match_all('/function getErrorCode\(\)[^{]*\{(.*?)\n    \}/s', $php, $bodies)) {
                foreach ($bodies[1] as $body) {
                    preg_match_all("/return '([a-z_0-9]+)'/", $body, $returned);
                    array_push($codes, ...$returned[1]);
                }
            }
            if (preg_match("/private readonly string \\\$errorCode = '([a-z_0-9]+)'/", $php, $default)) {
                $codes[] = $default[1];
            }
            preg_match_all("/Exception\([^;]*, '([a-z_0-9]+)'\)/", $php, $passed);
            array_push($codes, ...$passed[1]);
            preg_match_all("/'code'\s*=>\s*'([a-z_0-9]+)'/", $php, $bodyCodes);
            array_push($codes, ...$bodyCodes[1]);
            // Коды входа — значения LoginFailureEnum (TokenUseCase шлёт `$failure->value`).
            if (str_ends_with($file->getPathname(), '/LoginFailureEnum.php')) {
                preg_match_all("/case [A-Z_]+ = '([a-z_0-9]+)'/", $php, $cases);
                array_push($codes, ...$cases[1]);
            }
        }

        return $this->normalize($codes);
    }

    /**
     * @param array<string> $codes
     *
     * @return list<string>
     */
    private function normalize(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }
}
