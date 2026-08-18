<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Company\CompanyGetController;
use App\Iam\Controller\Company\CompanyUpdateController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PATCH /companies (FR-1.5.4): обновление реквизитов своей компании.
 * - admin обновляет реквизиты (legal_name/kpp/ogrn/address/contacts);
 * - пустая строка очищает значение (кроме legal_name);
 * - manager/agent — 403; без токена — 401.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class CompanyUpdateTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= static::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '14.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function login(string $email = VerifiedUserStory::EMAIL): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null === $data) {
            $client->request($method, $url, server: $server);
        } else {
            $client->request($method, $url, server: $server, content: json_encode($data, \JSON_UNESCAPED_UNICODE) ?: null);
        }

        return $client;
    }

    public function testAdminUpdatesCompany(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('PATCH', CompanyUpdateController::URL, $token, [
            'legal_name' => 'ООО Новое имя',
            'kpp' => '770101001',
            'ogrn' => '1027700132195',
            'address' => 'Москва, ул. Тестовая, 1',
            'contacts' => ['email' => 'info@new.loc', 'phone' => '+7 900 000-00-00'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('ООО Новое имя', $body['legal_name']);
        self::assertSame('770101001', $body['kpp']);
        self::assertSame('1027700132195', $body['ogrn']);
        self::assertSame('Москва, ул. Тестовая, 1', $body['address']);
        self::assertIsArray($body['contacts']);
        self::assertSame('info@new.loc', $body['contacts']['email']);

        // персистентность
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(\App\Iam\Entity\Company::class)->find($company->getId());
        self::assertNotNull($fresh);
        self::assertSame('ООО Новое имя', $fresh->getLegalName());
    }

    public function testEmptyStringClearsOptionalField(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        // сначала зададим адрес, потом очистим
        self::request('PATCH', CompanyUpdateController::URL, $token, ['address' => 'Москва']);
        $client = self::request('PATCH', CompanyUpdateController::URL, $token, ['address' => '']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['address']);
    }

    public function testGetCompanyReturnsOwnCard(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('GET', CompanyGetController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $company->getId(), $body['id']);
        self::assertSame($company->getLegalName(), $body['legal_name']);
    }

    public function testManagerForbidden(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $manager = UserFactory::createOne([
            'email' => 'manager-co@test.loc',
            'role' => UserRoleEnum::MANAGER,
            'companyId' => $company->getId(),
            'password' => UserFactory::PASSWORD,
        ]);
        self::assertNotNull($manager->getId());
        $token = self::login('manager-co@test.loc');

        $client = self::request('PATCH', CompanyUpdateController::URL, $token, [
            'legal_name' => 'Попытка менеджера',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRequiresAuthentication(): void
    {
        self::client();
        self::request('PATCH', CompanyUpdateController::URL, 'invalid-token', ['legal_name' => 'X']);
        self::assertResponseStatusCodeSame(401);
    }
}
