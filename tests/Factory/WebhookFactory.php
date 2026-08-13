<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Webhook>
 *
 * @method        Webhook   create(array<string, mixed>|callable $attributes = [])
 * @method static Webhook   createOne(array<string, mixed> $attributes = [])
 * @method static Webhook   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Webhook[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Webhook   find(object|array|mixed $criteria)
 * @method static Webhook   findOrCreate(array<string, mixed> $attributes)
 * @method static Webhook   first(string $sortBy = 'id')
 * @method static Webhook   last(string $sortBy = 'id')
 * @method static Webhook   random(array<string, mixed> $attributes = [])
 * @method static Webhook   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Webhook[] all()
 * @method static Webhook[] findBy(array<string, mixed> $attributes)
 * @method static Webhook[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Webhook[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Webhook     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Webhook     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Webhook> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Webhook> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Webhook     find(object|array|mixed $criteria)
 * @phpstan-method static Webhook     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Webhook     first(string $sortBy = 'id')
 * @phpstan-method static Webhook     last(string $sortBy = 'id')
 * @phpstan-method static Webhook     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Webhook     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Webhook> all()
 * @phpstan-method static list<Webhook> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Webhook> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Webhook> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class WebhookFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Webhook::class;
    }

    protected function defaults(): array
    {
        return [
            'tenantId' => \Zenstruck\Foundry\LazyValue::new(static fn (): \Symfony\Component\Uid\Uuid => CompanyFactory::createOne()->getId()),
            'url' => 'https://example.com/hooks/'.self::faker()->unique()->slug(2),
            'secret' => bin2hex(random_bytes(32)),
            'events' => ['tender.published', 'tender.updated'],
            'filters' => null,
            'status' => WebhookStatusEnum::ACTIVE,
        ];
    }

    /**
     * Подписка на одно конкретное событие (для тестов доставки).
     */
    public function forEvent(string $event): static
    {
        return $this->with(['events' => [$event]]);
    }

    public function paused(): static
    {
        return $this->with(['status' => WebhookStatusEnum::PAUSED]);
    }
}
