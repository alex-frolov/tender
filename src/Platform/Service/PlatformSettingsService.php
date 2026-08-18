<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Platform\Entity\PlatformSetting;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Настройки платформы (platform_settings, FR-1.5.16).
 *
 * - timezone_default — доменный часовой пояс платформы (IANA-идентификатор),
 *   применяется к расчётам сроков; дефолт — env DOMAIN_TIMEZONE (Europe/Moscow).
 * Хранилище key/value; GET отдаёт сохранённое значение или дефолт из env.
 */
final readonly class PlatformSettingsService
{
    public const string TIMEZONE_DEFAULT_KEY = 'timezone_default';

    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private string $defaultTimezone,
    ) {
    }

    /**
     * Доменный часовой пояс платформы (GET /platform/timezone).
     */
    public function timezone(): string
    {
        $setting = $this->em->getRepository(PlatformSetting::class)->find(self::TIMEZONE_DEFAULT_KEY);
        if (null !== $setting && null !== $setting->getValue() && '' !== $setting->getValue()) {
            return $setting->getValue();
        }

        return $this->defaultTimezone;
    }

    /**
     * Установка доменного часового пояса (PUT /platform/timezone, суперадмин).
     *
     * @throws ValidationException если идентификатор не является валидным IANA
     */
    public function setTimezone(string $timezone, string $actorId, ?string $ip = null): string
    {
        $timezone = trim($timezone);
        if ('' === $timezone || !\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException('timezone_default must be a valid IANA identifier');
        }

        $before = $this->timezone();

        $setting = $this->em->getRepository(PlatformSetting::class)->find(self::TIMEZONE_DEFAULT_KEY);
        if (null === $setting) {
            $setting = new PlatformSetting(self::TIMEZONE_DEFAULT_KEY, $timezone);
            $this->em->persist($setting);
        } else {
            $setting->setValue($timezone);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'platform.timezone.updated',
            entityType: 'platform_setting',
            entityId: self::TIMEZONE_DEFAULT_KEY,
            actorType: 'user',
            actorId: $actorId,
            before: ['timezone_default' => $before],
            after: ['timezone_default' => $timezone],
            ip: $ip,
        );

        return $timezone;
    }
}
