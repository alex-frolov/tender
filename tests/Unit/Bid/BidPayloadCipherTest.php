<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bid;

use App\Bid\BidPayloadCipher;
use PHPUnit\Framework\TestCase;

/**
 * Шифрование содержимого заявки (FR-1.2.2): round-trip, отсутствие открытого
 * текста в шифротексте, устойчивость к подделке (AEAD) и привязка к ключу.
 */
final class BidPayloadCipherTest extends TestCase
{
    private const KEY = 'unit-test-encryption-key-0123456789abcdef';

    public function testRoundTripReturnsSamePayload(): void
    {
        $cipher = new BidPayloadCipher(self::KEY);
        $payload = [
            'part1' => ['consent' => true, 'characteristics' => ['spec' => 'A4', 'color' => 'white']],
            'part2_ref' => ['9f9c44e7-1b3e-4f6d-9c1a-8f9b4a1e2c3d'],
            'price_minor' => 950000,
            'price_basis' => 'net',
            'vat_rate' => 20.5,
        ];

        $encrypted = $cipher->encrypt($payload);
        $decrypted = $cipher->decrypt($encrypted);

        self::assertSame($payload, $decrypted);
    }

    /**
     * Шифротекст не содержит открытого текста (FR-1.2.2): ни ключей JSON,
     * ни значений. Содержимое заявки невидимо до вскрытия.
     */
    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $cipher = new BidPayloadCipher(self::KEY);
        $encrypted = $cipher->encrypt([
            'part1' => ['consent' => true, 'characteristics' => ['secret_marker' => 'SUPER-SECRET-VALUE']],
            'part2_ref' => ['doc-11111111-1111-1111-1111-111111111111'],
            'price_minor' => 1234567,
            'price_basis' => 'gross',
            'vat_rate' => 20.0,
        ]);

        foreach (['part1', 'part2_ref', 'price_minor', 'SUPER-SECRET-VALUE', '1234567', 'doc-11111111-1111-1111-1111-111111111111'] as $needle) {
            self::assertStringNotContainsString($needle, $encrypted, 'Ciphertext must not leak plaintext');
        }
    }

    /**
     * Одинаковый payload при разных nonce даёт разный шифротекст — записи
     * в БД не позволяют сравнить «одинаковые» заявки.
     */
    public function testEncryptionIsRandomized(): void
    {
        $cipher = new BidPayloadCipher(self::KEY);
        $payload = ['part1' => ['consent' => true]];

        self::assertNotSame($cipher->encrypt($payload), $cipher->encrypt($payload));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = new BidPayloadCipher(self::KEY);
        $encrypted = $cipher->encrypt(['part1' => ['consent' => true]]);

        // Портим один байт шифротекста (не nonce-префикс) → AEAD-тег не сходится.
        $last = \strlen($encrypted) - 1;
        $flipped = (\ord($encrypted[$last]) ^ 0x01) & 0xFF;
        $tampered = substr($encrypted, 0, $last).\chr($flipped);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function testDecryptionWithWrongKeyFails(): void
    {
        $cipher = new BidPayloadCipher(self::KEY);
        $encrypted = $cipher->encrypt(['part1' => ['consent' => true]]);

        $other = new BidPayloadCipher('another-key-0123456789abcdef0123456789');

        $this->expectException(\RuntimeException::class);
        $other->decrypt($encrypted);
    }
}
