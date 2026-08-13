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
 * Компания с пользователями разных ролей (FR-1.5.8/9).
 *
 * Сценарий для тестов управления пользователями: подтверждённая компания,
 * admin-владелец, manager и agent. Каждый пользователь ACTIVE (verified).
 *
 * Доступ через магические методы: UserManagementStory::admin() / ::manager() /
 * ::agent() / ::company().
 *
 * @method static User    admin()
 * @method static User    manager()
 * @method static User    agent()
 * @method static Company company()
 */
final class UserManagementStory extends Story
{
    public const ADMIN_EMAIL = 'admin@test.ru';
    public const MANAGER_EMAIL = 'manager@test.ru';
    public const AGENT_EMAIL = 'agent@test.ru';
    public const PASSWORD = 'secret123';

    public function build(): void
    {
        $company = CompanyFactory::new(['legalName' => 'ООО Управление'])->approved()->create();

        $admin = UserFactory::createOne([
            'email' => self::ADMIN_EMAIL,
            'name' => 'Админ',
            'role' => UserRoleEnum::ADMIN,
            'companyId' => $company->getId(),
            'password' => self::PASSWORD,
        ]);
        $manager = UserFactory::createOne([
            'email' => self::MANAGER_EMAIL,
            'name' => 'Менеджер',
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $company->getId(),
            'password' => self::PASSWORD,
        ]);
        $agent = UserFactory::createOne([
            'email' => self::AGENT_EMAIL,
            'name' => 'Агент',
            'role' => UserRoleEnum::AGENT,
            'companyId' => $company->getId(),
            'password' => self::PASSWORD,
        ]);

        $this->addState('company', $company);
        $this->addState('admin', $admin);
        $this->addState('manager', $manager);
        $this->addState('agent', $agent);
    }
}
