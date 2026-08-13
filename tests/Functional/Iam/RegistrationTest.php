<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\RegisterController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.4: регистрация компании + первый admin.
 * - 201: org pending + admin email_pending;
 * - 409: повторный ИНН;
 * - 422: невалидное тело.
 */
final class RegistrationTest extends WebTestCase
{
    /**
     * Клиент с уникальным IP — изоляция rate-limit счётчиков (общий Redis).
     */
    private static function createClientWithIp(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));

        return $client;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            throw new \LogicException('Cannot encode JSON');
        }

        return $json;
    }

    public function testRegisterCreatesCompanyAndAdmin(): void
    {
        $client = self::createClientWithIp();
        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], self::json([
            'company_name' => 'ООО Тест',
            'inn' => '7701234567',
            'org_type' => 'both',
            'email' => 'admin@test.ru',
            'password' => 'secret123',
            'user_name' => 'Иван Админов',
        ]));

        self::assertResponseStatusCodeSame(201);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('company_id', $body);
        self::assertArrayHasKey('user_id', $body);
        self::assertSame('pending', $body['verification_status']);

        // проверка в БД
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $org = $em->getRepository(Company::class)->find($body['company_id']);
        self::assertNotNull($org);
        self::assertSame('pending', $org->getVerificationStatus()->value);
        self::assertSame('7701234567', $org->getInn());

        $user = $em->getRepository(User::class)->find($body['user_id']);
        self::assertNotNull($user);
        self::assertSame(UserRoleEnum::ADMIN, $user->getRole());
        self::assertSame('email_pending', $user->getVerificationStatus()->value);
        self::assertNotNull($user->getPasswordHash());
        self::assertNotSame('secret123', $user->getPasswordHash(), 'пароль хранится хешем');
        self::assertSame(LocaleEnum::RU, $user->getLocale(), 'по умолчанию язык — русский');
    }

    public function testRegisterWithLocaleEnSavesUserLocale(): void
    {
        $client = self::createClientWithIp();
        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], self::json([
            'company_name' => 'ООО Инглиш',
            'inn' => '7707654321',
            'org_type' => 'customer',
            'email' => 'admin-en@test.ru',
            'password' => 'secret123',
            'user_name' => 'John Admin',
            'locale' => 'en',
        ]));

        self::assertResponseStatusCodeSame(201);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->find($body['user_id']);
        self::assertNotNull($user);
        self::assertSame(LocaleEnum::EN, $user->getLocale());
    }

    public function testRegisterWithInvalidLocaleReturns422(): void
    {
        $client = self::createClientWithIp();
        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], self::json([
            'company_name' => 'ООО Невалид',
            'inn' => '7707999888',
            'org_type' => 'both',
            'email' => 'admin-bad@test.ru',
            'password' => 'secret123',
            'user_name' => 'Иван',
            'locale' => 'de',
        ]));

        self::assertResponseStatusCodeSame(422);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertSame('Validation error', $body['title'] ?? null);
    }

    public function testDuplicateInnReturns409(): void
    {
        $client = self::createClientWithIp();
        $payload = self::json([
            'company_name' => 'ООО Тест',
            'inn' => '7701234567',
            'org_type' => 'both',
            'email' => 'admin@test.ru',
            'password' => 'secret123',
            'user_name' => 'Иван',
        ]);

        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], self::json([
            'company_name' => 'ООО Дубль',
            'inn' => '7701234567',
            'org_type' => 'supplier',
            'email' => 'other@test.ru',
            'password' => 'secret123',
            'user_name' => 'Пётр',
        ]));
        self::assertResponseStatusCodeSame(409);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertSame('Conflict', $body['title'] ?? null);
        self::assertSame('conflict', $body['code'] ?? null);
    }

    public function testInvalidBodyReturns422(): void
    {
        $client = self::createClientWithIp();
        $client->request('POST', RegisterController::URL, [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');
        self::assertResponseStatusCodeSame(422);
    }
}
