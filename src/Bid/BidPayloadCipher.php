<?php

declare(strict_types=1);

namespace App\Bid;

/**
 * Шифрование содержимого заявки (FR-1.2.2).
 *
 * Содержимое (part1, part2_ref, price) шифруется authenticated-шифрованием
 * XSalsa20-Poly1305 (sodium_crypto_secretbox) на ключе, выведенном из
 * ENCRYPTION_KEY (gernerichash → 32 байта). Формат хранения: nonce(24) +
 * ciphertext. Расшифровка возможна только на вскрытии.
 *
 * Инварианты:
 * - encrypt() никогда не возвращает открытый текст — только шифротекст;
 * - decrypt() отклоняет повреждённый/подделанный шифротекст (AEAD-тег);
 * - детерминированность: один ключ → одинаковый результат для одинаковых
 *   payload с одинаковым nonce; random nonce делает шифротексты уникальными.
 */
final readonly class BidPayloadCipher
{
    private const int NONCE_BYTES = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private string $key;

    public function __construct(string $encryptionKey)
    {
        $this->key = sodium_crypto_generichash(
            $encryptionKey,
            '',
            \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
    }

    /**
     * Шифрование содержимого заявки. Возвращает байтовую строку
     * (nonce + ciphertext) для хранения в encrypted_payload (BYTEA).
     *
     * @param array<string, mixed> $payload содержимое заявки (part1, part2_ref, price…)
     *
     * @throws \JsonException если payload не сериализуется в JSON
     */
    public function encrypt(array $payload): string
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(self::NONCE_BYTES);

        return $nonce.sodium_crypto_secretbox($json, $nonce, $this->key);
    }

    /**
     * Расшифровка содержимого заявки (на вскрытии, FR-1.2.3).
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException если шифротекст повреждён/подделан или не JSON
     */
    public function decrypt(string $encrypted): array
    {
        $nonce = substr($encrypted, 0, self::NONCE_BYTES);
        $ciphertext = substr($encrypted, self::NONCE_BYTES);

        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Bid payload decryption failed (tampered or wrong key)');
        }

        try {
            $data = json_decode($plain, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Bid payload is not valid JSON after decryption', 0, $e);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('Bid payload must be a JSON object after decryption');
        }

        /** @var array<string, mixed> $payload */
        $payload = $data;

        return $payload;
    }
}
