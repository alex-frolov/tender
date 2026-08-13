<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<NotificationSubscription>
 *
 * @method        NotificationSubscription   create(array<string, mixed>|callable $attributes = [])
 * @method static NotificationSubscription   createOne(array<string, mixed> $attributes = [])
 * @method static NotificationSubscription[] createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static NotificationSubscription[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static NotificationSubscription   find(object|array|mixed $criteria)
 * @method static NotificationSubscription   findOrCreate(array<string, mixed> $attributes)
 * @method static NotificationSubscription   first(string $sortBy = 'id')
 * @method static NotificationSubscription   last(string $sortBy = 'id')
 * @method static NotificationSubscription   random(array<string, mixed> $attributes = [])
 * @method static NotificationSubscription   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static NotificationSubscription[] all()
 * @method static NotificationSubscription[] findBy(array<string, mixed> $attributes)
 * @method static NotificationSubscription[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static NotificationSubscription[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method NotificationSubscription     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static NotificationSubscription     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<NotificationSubscription> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<NotificationSubscription> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static NotificationSubscription     find(object|array|mixed $criteria)
 * @phpstan-method static NotificationSubscription     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static NotificationSubscription     first(string $sortBy = 'id')
 * @phpstan-method static NotificationSubscription     last(string $sortBy = 'id')
 * @phpstan-method static NotificationSubscription     random(array<string, mixed> $attributes = [])
 * @phpstan-method static NotificationSubscription     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<NotificationSubscription> all()
 * @phpstan-method static list<NotificationSubscription> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<NotificationSubscription> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<NotificationSubscription> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class NotificationSubscriptionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return NotificationSubscription::class;
    }

    protected function defaults(): array
    {
        $user = UserFactory::createOne();

        return [
            'userId' => $user->getId(),
            'tenantId' => $user->getCompanyId() ?? \Zenstruck\Foundry\LazyValue::new(static fn (): \Symfony\Component\Uid\Uuid => CompanyFactory::createOne()->getId()),
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published', 'tender.updated'],
            'filters' => null,
            'digest' => false,
            'active' => true,
        ];
    }

    /**
     * Подписка на одно конкретное событие (для тестов доставки).
     */
    public function forEvent(string $event): static
    {
        return $this->with(['events' => [$event]]);
    }

    public function digest(): static
    {
        return $this->with(['digest' => true]);
    }

    public function inactive(): static
    {
        return $this->with(['active' => false]);
    }
}
