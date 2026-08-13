<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Document\Entity\DocumentType;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\DocumentTypeFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Zenstruck\Foundry\Story;

/**
 * Сценарий для тестов загрузки документов (AM-8, FR-1.1.5, FR-1.2.6).
 *
 * Заказчик с подтверждённой компанией и тендером, публичный и приватный типы
 * документов, а также «чужой» тенант (SUPPLIER) для проверки видимости.
 *
 * Доступ через магические методы: DocumentUploadStory::user() / ::company() /
 * ::tender() / ::publicType() / ::privateType() / ::other() / ::otherCompany().
 *
 * @method static User         user()
 * @method static Company      company()
 * @method static Tender       tender()
 * @method static DocumentType publicType()
 * @method static DocumentType privateType()
 * @method static User         other()
 * @method static Company      otherCompany()
 */
final class DocumentUploadStory extends Story
{
    public const CUSTOMER_EMAIL = 'doc-customer@test.ru';
    public const OTHER_EMAIL = 'doc-supplier@test.ru';
    public const PASSWORD = 'secret123';

    public function build(): void
    {
        $company = CompanyFactory::new(['legalName' => 'ООО Документы'])->approved()->create();
        $user = UserFactory::createOne([
            'email' => self::CUSTOMER_EMAIL,
            'name' => 'Заказчик Документов',
            'role' => UserRoleEnum::ADMIN,
            'companyId' => $company->getId(),
            'password' => self::PASSWORD,
        ]);

        $publicType = DocumentTypeFactory::new()->publicCustomer()->create();
        $privateType = DocumentTypeFactory::createOne(['ownerRole' => 'customer', 'visibility' => 'private']);
        $tender = TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);

        $otherCompany = CompanyFactory::createOne(['type' => CompanyTypeEnum::SUPPLIER]);
        $other = UserFactory::createOne([
            'email' => self::OTHER_EMAIL,
            'name' => 'Чужой Поставщик',
            'role' => UserRoleEnum::ADMIN,
            'companyId' => $otherCompany->getId(),
            'password' => self::PASSWORD,
        ]);

        $this->addState('user', $user);
        $this->addState('company', $company);
        $this->addState('tender', $tender);
        $this->addState('publicType', $publicType);
        $this->addState('privateType', $privateType);
        $this->addState('other', $other);
        $this->addState('otherCompany', $otherCompany);
    }
}
