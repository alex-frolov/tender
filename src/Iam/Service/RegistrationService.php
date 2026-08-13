<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Регистрация компании (FR-1.5.4).
 *
 * - создаётся компания (pending) + первый пользователь admin;
 * - повторный ИНН → исключение (409);
 * - email-подтверждение обязательно (FR-1.5.5) — пользователь рождается
 *   со статусом email_pending, на почту уходит токен подтверждения.
 */
final readonly class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerification,
    ) {
    }

    /**
     * @return array{company_id: string, user_id: string, verification_status: string}
     *
     * @throws ConflictException если ИНН уже зарегистрирован (409)
     */
    public function register(
        string $companyName,
        string $inn,
        CompanyTypeEnum $orgType,
        string $email,
        string $password,
        string $userName,
        ?LocaleEnum $locale = null,
    ): array {
        $existing = $this->em->getRepository(Company::class)->findOneBy(['inn' => $inn]);
        if (null !== $existing) {
            throw new ConflictException(\sprintf('Company with INN %s already exists', $inn));
        }

        $company = new Company($companyName, $inn, $orgType);
        $user = new User($email, $userName, UserRoleEnum::ADMIN, $company->getId(), $locale);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($company);
        $this->em->persist($user);
        $this->em->flush();

        // FR-1.5.5: письмо с токеном подтверждения email (статус → ACTIVE после верификации)
        $this->emailVerification->issue($user);

        return [
            'company_id' => (string) $company->getId(),
            'user_id' => (string) $user->getId(),
            'verification_status' => $company->getVerificationStatus()->value,
        ];
    }
}
