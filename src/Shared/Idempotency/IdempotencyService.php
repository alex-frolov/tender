<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Entity\IdempotencyKey;
use App\Shared\Repository\IdempotencyKeyRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Единый механизм идемпотентности мутаций (AR-4, testing-strategy.md §6).
 *
 * - begin(): резервирует ключ (tenant, key) и вычисляет хэш payload.
 *   Повторный ключ с тем же хэшем → REPLAY (сохранённый ответ);
 *   другой хэш → CONFLICT (409 idempotency_conflict);
 *   нового ключа нет → NEW (запись создаётся до выполнения мутации).
 * - complete(): сохраняет ответ (status + body) для последующего replay.
 * - deleteExpired(): retention idempotency_keys (TTL через expires_at).
 *
 * Ключ резервируется ДО мутации (запись без ответа), поэтому конкурентные
 * запросы с тем же ключом сериализуются unique-индексом (COALESCE(tenant,'')+key):
 * второй INSERT падает → CONFLICT.
 */
final readonly class IdempotencyService
{
    public function __construct(
        private EntityManagerInterface $em,
        private IdempotencyKeyRepository $repo,
        private ClockInterface $clock,
        private int $ttlSeconds,
    ) {
    }

    /**
     * Начать идемпотентную мутацию. Возвращает результат для принятия решения
     * (replay/conflict/new) и, в случае new, созданную запись.
     */
    public function begin(
        ?string $tenantId,
        string $key,
        string $method,
        string $path,
        string $requestHash,
    ): IdempotencyResult {
        $existing = $this->repo->findByTenantAndKey($tenantId, $key);
        if (null !== $existing) {
            if ($existing->isExpired($this->clock->now())) {
                // истёкший ключ — можно переиспользовать (retention)
                $this->em->remove($existing);
                $this->em->flush();
            } elseif (null !== $existing->getResponseStatus() && $existing->getRequestHash() === $requestHash) {
                return IdempotencyResult::replay($existing);
            } else {
                return IdempotencyResult::conflict();
            }
        }

        $now = $this->clock->now();
        $record = new IdempotencyKey(
            tenantId: $tenantId,
            key: $key,
            method: $method,
            path: $path,
            requestHash: $requestHash,
            createdAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $this->ttlSeconds)),
        );

        try {
            $this->em->persist($record);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // конкурентный запрос уже создал ключ с другим хэшем
            return IdempotencyResult::conflict();
        }

        return IdempotencyResult::new($record);
    }

    /**
     * Завершить мутацию: сохранить ответ для последующего replay.
     *
     * @param array<mixed> $body
     */
    public function complete(IdempotencyKey $record, int $status, array $body): void
    {
        $record->complete($status, $body);
        $this->em->flush();
    }

    /**
     * Отменить резервирование ключа (5xx): запись удаляется, retry разрешён.
     */
    public function discard(IdempotencyKey $record): void
    {
        $this->em->remove($record);
        $this->em->flush();
    }

    /**
     * SHA-256 от (method + path + body) — идентификатор payload (AR-4).
     */
    public function requestHash(string $method, string $path, string $body): string
    {
        return hash('sha256', \sprintf("%s\n%s\n%s", $method, $path, $body));
    }

    /**
     * Retention: удалить истёкшие ключи. Возвращает число удалённых.
     */
    public function deleteExpired(): int
    {
        return $this->repo->deleteExpired($this->clock->now());
    }
}
