<?php

declare(strict_types=1);

namespace App\Platform;

use App\Iam\Entity\User;
use App\Platform\Entity\ApiKey;
use App\Platform\Exception\ApiKeyNotFoundException;
use App\Platform\Input\CreateApiKeyInput;
use App\Platform\Repository\ApiKeyRepository;
use App\Platform\Service\ApiKeyScopes;
use App\Platform\Service\ApiKeyTokenFactory;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * API-ключи (FR-1.5.13, AR-3, openapi /api-keys).
 *
 * Жизненный цикл ключа компании-тенанта (право api_keys.manage — только admin):
 * - create — выпуск ключа от имени актора (PAT): name, scopes (каталог
 *   ApiKeyScopes), expires_at. Raw-токен генерируется и отдаётся один раз;
 *   в БД хранится только token_hash (AR-3);
 * - list — ключи компании без raw-токенов/hash;
 * - revoke — отзыв (revoked_at; raw-токен перестаёт действовать немедленно);
 * - rotate — ротация: новый raw-токен, старый аннулируется;
 * - resolve/recordLastUsed — аутентификация по ключу (ApiKeyAuthMiddleware).
 *
 * Tenant-изоляция: ключ принадлежит компании актора; чужой ключ невидим (404).
 * Каждая мутация пишет append-only аудит (FR-1.8).
 */
final readonly class ApiKeyService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyRepository $keys,
        private ApiKeyTokenFactory $tokens,
        private AuditService $audit,
    ) {
    }

    /**
     * Выпуск API-ключа (FR-1.5.13, POST /api-keys). Владелец — актор; тенант —
     * его компания. Raw-токен отдаётся один раз (в ответе создающего запроса).
     *
     * @return array{api_key: ApiKey, token: string}
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если scopes содержат неизвестный код или срок в прошлом
     */
    public function create(User $actor, CreateApiKeyInput $input): array
    {
        $tenantId = $this->requireCompany($actor);
        $scopes = $this->scopes($input->scopes);
        $expiresAt = $this->expiresAt($input->expiresAt);

        $generated = $this->tokens->generate();

        $key = new ApiKey(
            tenantId: $tenantId,
            userId: $actor->getId(),
            name: $this->name($input->name),
            tokenHash: $generated['hash'],
            scopes: $scopes,
            expiresAt: $expiresAt,
        );

        $this->em->persist($key);
        $this->em->flush();

        $this->audit->record(
            action: 'api_key.created',
            entityType: 'api_key',
            entityId: (string) $key->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'name' => $key->getName(),
                'scopes' => $scopes,
                'expires_at' => null !== $expiresAt ? $expiresAt->format('Y-m-d\TH:i:s\Z') : null,
            ],
        );
        $this->em->flush();

        return ['api_key' => $key, 'token' => $generated['token']];
    }

    /**
     * Список ключей компании (GET /api-keys, без raw-токенов и hash).
     *
     * @return list<ApiKey>
     */
    public function list(User $actor): array
    {
        return $this->keys->listForTenant($this->requireCompany($actor));
    }

    /**
     * Отзыв ключа (FR-1.5.13, DELETE /api-keys/{apiKeyId}): revoked_at
     * фиксируется, ключ перестаёт действовать (аутентификация по нему — 401).
     * Чужой ключ — 404.
     */
    public function revoke(User $actor, string $apiKeyId): ApiKey
    {
        $key = $this->resolveOwned($actor, $apiKeyId);

        $key->revoke();
        $this->em->flush();

        $this->audit->record(
            action: 'api_key.revoked',
            entityType: 'api_key',
            entityId: (string) $key->getId(),
            tenantId: (string) $key->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
        );
        $this->em->flush();

        return $key;
    }

    /**
     * Ротация ключа (FR-1.5.13, POST /api-keys/{apiKeyId}/rotate): выпускается
     * новый raw-токен (отдаётся один раз), старый аннулируется. Scopes/имя/срок
     * сохраняются. Чужой ключ — 404.
     *
     * @return array{api_key: ApiKey, token: string}
     */
    public function rotate(User $actor, string $apiKeyId): array
    {
        $key = $this->resolveOwned($actor, $apiKeyId);

        $generated = $this->tokens->generate();
        $key->rotate($generated['hash']);
        $this->em->flush();

        $this->audit->record(
            action: 'api_key.rotated',
            entityType: 'api_key',
            entityId: (string) $key->getId(),
            tenantId: (string) $key->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
        );
        $this->em->flush();

        return ['api_key' => $key, 'token' => $generated['token']];
    }

    /**
     * Аутентификация по raw-токену (AR-3): lookup по хэшу. Возвращает ключ,
     * если он не отозван и не просрочен (иначе null — запрос анонимный).
     *
     * @param non-empty-string $token raw-токен из X-API-Key / Bearer
     */
    public function resolve(string $token): ?ApiKey
    {
        $key = $this->keys->findByTokenHash($this->tokens->hash($token));
        if (null === $key || !$key->isActive()) {
            return null;
        }

        return $key;
    }

    /**
     * Фиксация времени последнего использования (last_used_at) при успешной
     * аутентификации. Ошибка БД не должна валить запрос — best-effort.
     */
    public function recordLastUsed(ApiKey $key): void
    {
        try {
            $key->markLastUsed();
            $this->em->flush();
        } catch (\Throwable) {
            // best-effort: аудит использования — не критический путь
        }
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * @throws ApiKeyNotFoundException если ключ не найден или чужой
     */
    private function resolveOwned(User $actor, string $apiKeyId): ApiKey
    {
        $tenantId = $this->requireCompany($actor);
        $key = $this->keys->findById($apiKeyId);
        if (null === $key || !$key->getTenantId()->equals($tenantId)) {
            throw new ApiKeyNotFoundException('API key not found');
        }

        return $key;
    }

    /**
     * Имя ключа (1–100 символов, без пустых).
     *
     * @throws ValidationException
     */
    private function name(string $value): string
    {
        $name = trim($value);
        if ('' === $name || \strlen($name) > 100) {
            throw new ValidationException('name must be between 1 and 100 characters');
        }

        return $name;
    }

    /**
     * Scopes ключа (FR-1.5.13): дедупликация, валидация по каталогу.
     * Пустое/не переданное → [] (полный доступ владельца, ApiKeyScopeMap).
     *
     * @param list<string>|null $value
     *
     * @return list<string>
     *
     * @throws ValidationException если scope вне каталога ApiKeyScopes
     */
    private function scopes(?array $value): array
    {
        if (null === $value || [] === $value) {
            return [];
        }

        $scopes = [];
        foreach ($value as $scope) {
            if (!\is_string($scope) || !ApiKeyScopes::isValid([$scope])) {
                throw new ValidationException(\sprintf('invalid scope "%s"', \is_string($scope) ? $scope : '?'));
            }
            $scopes[$scope] = $scope;
        }

        return array_values($scopes);
    }

    /**
     * Срок действия (ISO-8601, nullable). Срок в прошлом отклоняется.
     *
     * @throws ValidationException
     */
    private function expiresAt(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new ValidationException('expires_at must be ISO-8601 datetime');
        }

        if ($date < new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new ValidationException('expires_at must be in the future');
        }

        return $date;
    }
}
