<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Totp;

use App\Shared\Totp\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP (RFC 6238). Векторы — RFC 6238 Appendix B (HMAC-SHA1).
 *
 * Секрет: "12345678901234567890" (ASCII, 20 байт)
 *   T=59        → 94287082  (8 digits в RFC; у нас 6 → берём остаток)
 *   T=1111111109 → 07081804
 *   T=1111111111 → 14050471
 *   T=1234567890 → 89005924
 *   T=2000000000 → 69279037
 *   T=20000000000 → 65353130
 *
 * RFC использует 8 цифр; наш сервис выдаёт 6 — проверяем суффикс 6 цифр
 * (эквивалент: код = value % 1_000_000).
 */
final class TotpServiceTest extends TestCase
{
    /** ASCII "12345678901234567890" в base32 (RFC 4648) — так хранятся секреты в проде */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return list<array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Sha1Vectors(int $timestamp, string $expected8): void
    {
        $service = new TotpService();
        $code = $service->generate(self::RFC_SECRET, $timestamp);

        // ожидаем 6-значный код = последние 6 цифр 8-значного
        $expected6 = substr($expected8, -6);
        self::assertSame($expected6, $code);
    }

    public function testBase32SecretDecoding(): void
    {
        $service = new TotpService();

        // регистр и пробелы не влияют на декодирование base32
        $codeUpper = $service->generate('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59);
        $codeLower = $service->generate('gezdgnbvgy3tqojqgezdgnbvgy3tqojq', 59);

        self::assertSame($codeUpper, $codeLower);
        self::assertSame('287082', $codeUpper); // суффикс 6 цифр от 94287082 (RFC 6238, T=59)
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $service = new TotpService();
        $secret = 'JBSWY3DPEHPK3PXP'; // "Hello!\xDE\xAD\xBE\xEF" в base32
        $code = $service->generate($secret);

        self::assertTrue($service->verify($secret, $code));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $service = new TotpService();
        $secret = 'JBSWY3DPEHPK3PXP';

        self::assertFalse($service->verify($secret, '000000'));
        self::assertFalse($service->verify($secret, 'abc123'));
        self::assertFalse($service->verify($secret, ''));
    }

    public function testVerifyWithWindow(): void
    {
        $service = new TotpService();
        $secret = 'JBSWY3DPEHPK3PXP';

        // код предыдущего периода (time - 30) должен проходить при window=1
        $past = time() - 30;
        $pastCode = $service->generate($secret, $past);

        self::assertTrue($service->verify($secret, $pastCode, 1));
        self::assertFalse($service->verify($secret, $pastCode, 0));
    }

    public function testInvalidBase32Throws(): void
    {
        $service = new TotpService();

        $this->expectException(\InvalidArgumentException::class);
        $service->generate('INVALID!CHAR');
    }

    public function testOtpAuthUri(): void
    {
        $service = new TotpService();
        $uri = $service->otpauthUri('JBSWY3DPEHPK3PXP', 'Tender Platform', 'user@example.com');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Tender%20Platform', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
    }

    public function testCodeIsAlwaysSixDigits(): void
    {
        $service = new TotpService();

        for ($i = 0; $i < 20; ++$i) {
            $code = $service->generate('JBSWY3DPEHPK3PXP', 1_000_000 + $i * 30);
            self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }
}
