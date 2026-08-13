<?php

declare(strict_types=1);

namespace App\Iam\Service;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Выпуск и проверка JWT access-токенов (FR-1.5.3).
 *
 * - HS256, секрет из AUTH_JWT_SECRET;
 * - claims: sub=user_id, org=company_id, role, jti, iat, exp, iss;
 * - отзыв access-токена невозможен (stateless) — короткий TTL (AUTH_ACCESS_TTL)
 *   и проверка статуса пользователя при каждом запросе (AuthMiddleware);
 * - refresh-токены — непрозрачные (RefreshToken-сущность), не JWT.
 */
final class JwtService
{
    private const string ISSUER = 'tender-platform';

    private readonly Configuration $config;

    public function __construct(
        #[\SensitiveParameter] string $secret,
        private readonly ClockInterface $clock,
        private readonly int $accessTtl,
    ) {
        \assert('' !== $secret, 'AUTH_JWT_SECRET must not be empty');
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret),
        );
    }

    /**
     * Выпуск access-токена.
     *
     * @return array{token: string, expires_in: int}
     */
    public function issue(Uuid $userId, ?Uuid $companyId, string $role, ?string $jti = null): array
    {
        $now = $this->clock->now();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->accessTtl));
        \assert($expiresAt instanceof \DateTimeImmutable);

        $builder = $this->config->builder()
            ->issuedBy(self::ISSUER)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now) // nbf: требуется StrictValidAt при валидации
            ->expiresAt($expiresAt)
            ->relatedTo((string) $userId)
            ->withClaim('role', $role);

        if (null !== $companyId) {
            $builder = $builder->withClaim('org', (string) $companyId);
        }

        if (null !== $jti) {
            \assert('' !== $jti);
            $builder = $builder->identifiedBy($jti);
        }

        $token = $builder->getToken($this->config->signer(), $this->config->signingKey());

        return [
            'token' => $token->toString(),
            'expires_in' => $this->accessTtl,
        ];
    }

    public function accessTtl(): int
    {
        return $this->accessTtl;
    }

    /**
     * Парсинг и валидация (подпись + время жизни). Возвращает токен
     * или null — невалидный/просроченный/подделанный.
     */
    public function parse(string $jwt): ?Plain
    {
        if ('' === $jwt) {
            return null;
        }

        try {
            $token = $this->config->parser()->parse($jwt);
        } catch (\Throwable) {
            return null;
        }

        if (!$token instanceof Plain) {
            return null;
        }

        try {
            $this->config->validator()->assert(
                $token,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt($this->clock),
            );
        } catch (RequiredConstraintsViolated) {
            return null;
        }

        return $token;
    }

    /**
     * Извлечение claims из валидированного токена.
     *
     * @return array{user_id: string, company_id: ?string, role: ?string, jti: ?string}
     */
    public function claims(Plain $token): array
    {
        $claims = $token->claims();

        $userId = $claims->get(RegisteredClaims::SUBJECT);
        \assert(\is_string($userId));

        $companyId = $claims->get('org');
        $role = $claims->get('role');
        $jti = $claims->get(RegisteredClaims::ID);

        return [
            'user_id' => $userId,
            'company_id' => \is_string($companyId) ? $companyId : null,
            'role' => \is_string($role) ? $role : null,
            'jti' => \is_string($jti) ? $jti : null,
        ];
    }
}
