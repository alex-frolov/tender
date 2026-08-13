<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use App\Shared\Totp\TotpService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Управление 2FA (FR-1.5.3): включение, подтверждение, отключение.
 *
 * - setup: генерирует секрет (base32), возвращает otpauth-URI для QR;
 * - confirm: проверяет код и активирует 2FA;
 * - disable: требует код (подтверждение владения) и отключает.
 */
final readonly class TwoFactorService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TotpService $totp,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array{secret: string, otpauth_uri: string}
     *
     * @throws ConflictException если 2FA уже включена (409)
     */
    public function setup(User $user): array
    {
        if ($user->isTwoFactorEnabled()) {
            throw new ConflictException('2FA already enabled');
        }

        $secret = $this->generateSecret();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri($secret, 'Tender Platform', $user->getEmail()),
        ];
    }

    /**
     * Подтверждение и включение 2FA.
     *
     * @throws ValidationException если код неверен (422)
     */
    public function confirm(User $user, string $secret, string $code): void
    {
        if (!$this->totp->verify($secret, $code)) {
            throw new ValidationException('Invalid TOTP code');
        }

        $user->enableTwoFactor($secret);
        $this->em->flush();

        $this->audit->record(
            action: 'auth.2fa_enabled',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
        );
    }

    /**
     * Отключение 2FA (требует корректный код).
     *
     * @throws ValidationException если код неверен (422)
     */
    public function disable(User $user, string $code): void
    {
        $secret = $user->getTotpSecret();
        if (null === $secret || !$this->totp->verify($secret, $code)) {
            throw new ValidationException('Invalid TOTP code');
        }

        $user->disableTwoFactor();
        $this->em->flush();

        $this->audit->record(
            action: 'auth.2fa_disabled',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: $this->tenantIdOf($user),
            actorType: 'user',
            actorId: (string) $user->getId(),
        );
    }

    private function tenantIdOf(User $user): ?string
    {
        return null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null;
    }

    private function generateSecret(): string
    {
        // 20 байт энтропии → 32 символа base32 (RFC 6238 рекомендует ≥ 128 бит)
        $bytes = random_bytes(20);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $secret = '';
        $bitBuffer = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $bitBuffer = ($bitBuffer << 8) | \ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $secret .= $alphabet[($bitBuffer >> ($bits - 5)) & 0x1F];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $secret .= $alphabet[($bitBuffer << (5 - $bits)) & 0x1F];
        }

        return $secret;
    }
}
