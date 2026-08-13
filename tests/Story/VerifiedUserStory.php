<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Story;

/**
 * Подтверждённый (ACTIVE) пользователь с подтверждённой компанией.
 *
 * Обёртка над фабриками: вызывает CompanyFactory (approved) и UserFactory
 * с заданной ролью. Используется в функциональных тестах аутентификации.
 *
 * Доступ к объектам через магические методы: VerifiedUserStory::user() / ::company().
 *
 * @method static User    user()
 * @method static Company company()
 */
final class VerifiedUserStory extends Story
{
    public const EMAIL = 'auth@test.ru';
    public const PASSWORD = 'secret123';

    public function build(): void
    {
        $company = CompanyFactory::new(['legalName' => 'ООО Аутентификация'])->approved()->create();
        $user = UserFactory::createOne([
            'email' => self::EMAIL,
            'name' => 'Тест Авторизации',
            'role' => UserRoleEnum::ADMIN,
            'companyId' => $company->getId(),
            'password' => self::PASSWORD,
        ]);

        $this->addState('user', $user);
        $this->addState('company', $company);
    }
}
