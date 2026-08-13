<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Input\RegisterInput;
use App\Iam\Service\RegistrationService;

/**
 * Регистрация компании + первого администратора (FR-1.5.4/1.5.6, POST /auth/register).
 *
 * Прикладной слой: конвертация строковых полей DTO в enum-типы сервиса
 * (валидацию значений org_type/locale уже выполнила форма RegisterType —
 * ChoiceType по CompanyTypeEnum::getValues()). Оркестрация (создание компании
 * pending + admin, письмо подтверждения email, 409 при повторном ИНН) —
 * в доменном RegistrationService. Ответ — созданные id со статусом
 * верификации компании; HTTP 201 проставляет контроллер (фиксированный статус).
 */
final readonly class RegisterUseCase implements IamUseCase
{
    public function __construct(private RegistrationService $registration)
    {
    }

    /**
     * @return array{company_id: string, user_id: string, verification_status: string}
     */
    public function execute(RegisterInput $input): array
    {
        return $this->registration->register(
            companyName: $input->companyName,
            inn: $input->inn,
            orgType: CompanyTypeEnum::from($input->orgType),
            email: $input->email,
            password: $input->password,
            userName: $input->userName,
            locale: null !== $input->locale ? LocaleEnum::from($input->locale) : null,
        );
    }
}
