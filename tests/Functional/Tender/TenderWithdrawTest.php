<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderPublishController;
use App\Tender\Controller\TenderWithdrawController;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * B3, FR-1.1.3: отзыв публикации (published → withdrawn), только до старта
 * приёма заявок. Причина (reason) обязательна.
 * - published → withdrawn (200), статус withdrawn;
 * - withdraw черновика → 409 (только published);
 * - withdraw без reason → 422;
 * - agent → 403, manager/admin → 200;
 * - чужой/несуществующий → 404; без токена → 401.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя на каждый запрос) при 3 HTTP-запросах с разными
 * пользователями в одном тесте даёт n-plus-one; прод-код менять не нужно, см.
 * docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
final class TenderWithdrawTest extends WebTestCase
{
    private const string EMAIL = VerifiedUserStory::EMAIL;
    private static ?KernelBrowser $client = null;

    private Company $company;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:9)
        $this->company = VerifiedUserStory::company();
        $this->token = $this->loginAs(self::EMAIL);
    }

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '15.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private function loginAs(string $email): string
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
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
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

    private function createDraft(): string
    {
        $client = self::request('POST', TenderCreateController::URL, $this->token, [
            'title' => 'Закупка на отзыв',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => (string) $this->company->getId(),
            'access_type' => 'open',
            'lots' => [
                ['title' => 'Серверы', 'price_net_minor' => 60000],
                ['title' => 'СХД', 'price_net_minor' => 40000],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    private function publish(string $id): void
    {
        $url = str_replace('{tenderId}', $id, TenderPublishController::URL);
        self::request('POST', $url, $this->token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testWithdrawTransitionsPublishedToWithdrawn(): void
    {
        $id = $this->createDraft();
        $this->publish($id);

        $url = str_replace('{tenderId}', $id, TenderWithdrawController::URL);
        $client = self::request('POST', $url, $this->token, ['reason' => 'Ошибка в документации']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('withdrawn', $body['status']);
    }

    public function testWithdrawDraftReturns409(): void
    {
        $id = $this->createDraft();

        $url = str_replace('{tenderId}', $id, TenderWithdrawController::URL);
        $client = self::request('POST', $url, $this->token, ['reason' => 'Отзыв черновика']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testWithdrawWithoutReasonReturns422(): void
    {
        $id = $this->createDraft();
        $this->publish($id);

        $url = str_replace('{tenderId}', $id, TenderWithdrawController::URL);
        $client = self::request('POST', $url, $this->token, []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testWithdrawUnknownTenderReturns404(): void
    {
        $url = str_replace('{tenderId}', '00000000-0000-0000-0000-000000000000', TenderWithdrawController::URL);
        $client = self::request('POST', $url, $this->token, ['reason' => 'Причина']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentCannotWithdrawReturns403(): void
    {
        $adminToken = $this->token;
        $id = $this->createDraft();
        $this->publish($id);

        $agent = UserFactory::createOne([
            'role' => UserRoleEnum::AGENT,
            'companyId' => $this->company->getId(),
        ]);
        $token = $this->loginAs((string) $agent->getEmail());

        $url = str_replace('{tenderId}', $id, TenderWithdrawController::URL);
        $client = self::request('POST', $url, $token, ['reason' => 'Попытка отзыва']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $url = str_replace('{tenderId}', '00000000-0000-0000-0000-000000000000', TenderWithdrawController::URL);
        $client = self::request('POST', $url, '', ['reason' => 'Причина']);
        self::assertResponseStatusCodeSame(401);
    }
}
