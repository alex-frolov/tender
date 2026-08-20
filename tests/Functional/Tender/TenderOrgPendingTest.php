<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderListController;
use App\Tender\Controller\TenderPublishController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.5.7 (org_pending): пока компания не подтверждена суперадмином,
 * заказчик не может создавать и публиковать тендеры — 403 с кодом org_pending.
 * Просмотр доски при этом доступен всем (в т.ч. pending-компании).
 *
 * Ограничение действует на роль с правом (admin), то есть проверяется именно
 * статус компании, а не отсутствие permission.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderOrgPendingTest extends WebTestCase
{
    private const string EMAIL = 'pending-company@test.ru';
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
        return '13.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function login(string $email): string
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
     * @param array<mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = []): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    /**
     * @return array{0: string, 1: Company} токен админа и его компания (статус pending)
     */
    private static function pendingCompanyAdmin(): array
    {
        // Без ->approved(): компания остаётся в дефолтном статусе pending.
        $company = CompanyFactory::createOne(['legalName' => 'ООО Непроверенная']);
        UserFactory::createOne([
            'email' => self::EMAIL,
            'companyId' => $company->getId(),
        ]);

        return [self::login(self::EMAIL), $company];
    }

    public function testPendingCompanyCannotCreateTender(): void
    {
        self::client();
        [$token, $company] = self::pendingCompanyAdmin();

        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => 'Закупка от неподтверждённой компании',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => (string) $company->getId(),
            'lots' => [['title' => 'Лот', 'price_net_minor' => 100000]],
        ]);

        self::assertResponseStatusCodeSame(403);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('org_pending', $body['code']);
    }

    public function testPendingCompanyCannotPublishTender(): void
    {
        self::client();
        [$token, $company] = self::pendingCompanyAdmin();

        // Черновик заведён в обход API (создание тоже под запретом).
        $tender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
            'nmckMinor' => 100000,
        ]);
        LotFactory::createOne([
            'tender' => $tender,
            'number' => 1,
            'title' => 'Лот',
            'priceNetMinor' => 100000,
        ]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $url = str_replace('{tenderId}', (string) $tender->getId(), TenderPublishController::URL);
        $client = self::request('POST', $url, $token, null);

        self::assertResponseStatusCodeSame(403);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('org_pending', $body['code']);
    }

    public function testPendingCompanyStillSeesTenderBoard(): void
    {
        self::client();
        [$token] = self::pendingCompanyAdmin();

        self::request('GET', TenderListController::URL, $token, null);
        self::assertResponseStatusCodeSame(200);
    }
}
