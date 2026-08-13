<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Service\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * Подпись HMAC-SHA256 webhook (WH-3): формат X-Signature «sha256=<hex>»,
 * известный вектор (RFC-тест) и чувствительность к секрету/payload.
 */
final class WebhookSignerTest extends TestCase
{
    public function testSignatureFormatAndKnownVector(): void
    {
        $signer = new WebhookSigner();

        // Известный вектор HMAC-SHA256 («key»/«The quick brown fox jumps over the lazy dog»).
        $expected = 'sha256=f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8';
        self::assertSame($expected, $signer->signature('The quick brown fox jumps over the lazy dog', 'key'));
    }

    public function testSignatureDependsOnSecret(): void
    {
        $signer = new WebhookSigner();
        $payload = '{"event_type":"tender.published"}';

        self::assertNotSame(
            $signer->signature($payload, 'secret-a-16-chars!'),
            $signer->signature($payload, 'secret-b-16-chars!'),
        );
    }

    public function testSignatureDependsOnPayload(): void
    {
        $signer = new WebhookSigner();
        $secret = '0123456789abcdef0123456789abcdef';

        self::assertNotSame(
            $signer->signature('{"event_id":"a"}', $secret),
            $signer->signature('{"event_id":"b"}', $secret),
        );
    }
}
