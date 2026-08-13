<?php

declare(strict_types=1);

namespace App\Shared\Input;

use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Symfony\Component\Uid\Uuid;

/**
 * Общие парсеры/проверки входных данных на границе сервисов (фасадов модулей).
 *
 * Единая точка для «компания актора», «uuid-поле», «дата-поле», «диапазон дат».
 * Все методы бросают ApiException (ValidationException/ConflictException) →
 * JSON через JsonApiExceptionSubscriber.
 * Статические и без состояния — чистая валидация, не сервис.
 */
final class InputValue
{
    private function __construct()
    {
    }

    /**
     * Компания действующего пользователя (все мутации tenant-изолированы).
     *
     * @throws ConflictException если у пользователя нет компании
     */
    public static function companyId(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * Парсинг UUID-поля входа (id сущностей).
     *
     * @throws ValidationException если значение отсутствует или не UUID
     */
    public static function uuid(?string $value, string $field): Uuid
    {
        if (null === $value || '' === $value || !Uuid::isValid($value)) {
            throw new ValidationException(\sprintf('%s must be a valid UUID', $field));
        }

        return Uuid::fromString($value);
    }

    /**
     * Парсинг даты-поля входа (UTC). null/пусто → null.
     *
     * @throws ValidationException если значение не дата
     */
    public static function date(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new ValidationException(\sprintf('%s must be a valid date', $field));
        }
    }

    /**
     * @throws ValidationException если $to ≤ $from (оба указаны)
     */
    public static function assertDateRange(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $fromField,
        string $toField,
    ): void {
        if (null !== $from && null !== $to && $to <= $from) {
            throw new ValidationException(\sprintf('%s must be after %s', $toField, $fromField));
        }
    }
}
