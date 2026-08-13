<?php

declare(strict_types=1);

namespace App\Shared\Totp;

/**
 * TOTP (RFC 6238, HMAC-SHA1, 6 цифр, 30 сек).
 *
 * Реализация с нуля — без внешних зависимостей (в стиле Money-сервиса):
 * base32-декодирование секрета, HMAC-SHA1, динамическая обрезка (RFC 4226).
 *
 * Тесты: RFC 6238 Appendix B (векторы SHA1) + пограничные случаи.
 */
final class TotpService
{
    public const int PERIOD = 30;
    public const int DIGITS = 6;

    /**
     * Проверка кода с окном допуска ±$window периодов (anti-clock-drift).
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        if ('' === $code || !preg_match('/^\d+$/', $code)) {
            return false;
        }

        $key = $this->base32Decode($secret);
        $counter = (int) floor(time() / self::PERIOD);

        for ($i = -$window; $i <= $window; ++$i) {
            if (hash_equals($this->generateAt($key, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Генерация текущего кода (для тестов и отладки).
     */
    public function generate(string $secret, ?int $at = null): string
    {
        $key = $this->base32Decode($secret);
        $counter = (int) floor(($at ?? time()) / self::PERIOD);

        return $this->generateAt($key, $counter);
    }

    /**
     * otpauth:// URI для QR-кода (Google Authenticator / 1Password).
     */
    public function otpauthUri(string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode(\sprintf('%s:%s', $issuer, $account));
        $issuerEncoded = rawurlencode($issuer);

        return \sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            $issuerEncoded,
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * @return string 6-значный код для заданного счётчика
     */
    private function generateAt(string $key, int $counter): string
    {
        $message = pack('N*', 0, $counter); // 8 байт big-endian
        $hash = hash_hmac('sha1', $message, $key, true); // 20 байт

        // dynamic truncation (RFC 4226 §5.3)
        $offset = \ord($hash[19]) & 0x0F;
        $binary = (
            ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % 10 ** self::DIGITS;

        return str_pad((string) $otp, self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /**
     * Base32 decode (RFC 4648, без padding).
     */
    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(str_replace(' ', '', $secret));
        $secret = rtrim($secret, '=');

        $bitBuffer = 0;
        $bits = 0;
        $output = '';

        $length = \strlen($secret);
        for ($i = 0; $i < $length; ++$i) {
            $char = $secret[$i];
            $value = strpos($alphabet, $char);
            if (false === $value) {
                throw new \InvalidArgumentException(\sprintf('Invalid base32 character: %s', $char));
            }

            $bitBuffer = ($bitBuffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $output .= \chr(($bitBuffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $output;
    }
}
