<?php

declare(strict_types=1);

namespace App\Tests\Functional\Iam;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Controller\Company\CompanyVerifyController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.7: подтверждение компании суперадмином + org_pending-ограничения.
 * - только platform_admin модерирует (approve/reject/suspend);
 * - pending → active (approve), pending → rejected (reject с причиной),
 *   active → suspended (suspend);
 * - не подтверждённая компания блокирует бизнес-действия (CompanyAccessGuard).
 */
final class CompanyModerationTest extends WebTestCase
{
    private const PLATFORM_EMAIL = 'sa@test.ru';
    private const PASSWORD = 'secret123';

    /** @var KernelBrowser|null один клиент на тест */
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
        return '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function platformAdmin(): User
    {
        return UserFactory::createOne([
            'email' => self::PLATFORM_EMAIL,
            'name' => 'Суперадмин',
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'password' => self::PASSWORD,
        ]);
    }

    private static function login(string $email = self::PLATFORM_EMAIL): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            self::json(['email' => $email, 'password' => self::PASSWORD]),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @param array<mixed> $data
     */
    private static function verify(string $url, string $token, array $data): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            self::json($data),
        );

        return $client;
    }

    public function testPlatformAdminApprovesPendingCompany(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::createOne();
        self::assertSame(CompanyStatusEnum::PENDING, $company->getVerificationStatus());

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'approve']);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('active', $body['verification_status']);
        self::assertNotNull($body['verified_at']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Company::class)->find((string) $company->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive());
    }

    public function testPlatformAdminRejectsPendingCompanyWithReason(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::createOne();

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'reject', 'reason' => 'Документы не устроили']);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('rejected', $body['verification_status']);
    }

    public function testRejectWithoutReasonReturns422(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::createOne();

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'reject']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPlatformAdminSuspendsActiveCompany(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::new()->approved()->create();
        self::assertTrue($company->isActive());

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'suspend']);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('suspended', $body['verification_status']);
    }

    public function testSuspendPendingCompanyReturns409(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::createOne();

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'suspend']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonPlatformActorForbidden(): void
    {
        self::client();
        $owner = UserFactory::createOne([
            'role' => UserRoleEnum::ADMIN,
            'email' => 'admin@test.ru',
            'password' => self::PASSWORD,
        ]);
        $company = CompanyFactory::createOne(['inn' => '7700000001']);

        // обычный admin логинится под своим (не platform) аккаунтом
        $token = self::login('admin@test.ru');
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'approve']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        self::client();
        $company = CompanyFactory::createOne();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, '', ['action' => 'approve']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidActionReturns422(): void
    {
        self::client();
        self::platformAdmin();
        $company = CompanyFactory::createOne();

        $token = self::login();
        $url = str_replace('{companyId}', (string) $company->getId(), CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'delete']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownCompanyReturns404(): void
    {
        self::client();
        self::platformAdmin();
        $token = self::login();
        $url = str_replace('{companyId}', '00000000-0000-0000-0000-000000000000', CompanyVerifyController::URL);
        $client = self::verify($url, $token, ['action' => 'approve']);
        self::assertResponseStatusCodeSame(404);
    }
}
