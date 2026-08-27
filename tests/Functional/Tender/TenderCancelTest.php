<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderCancelController;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderPublishController;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FR-1.1.8: отмена тендера с причиной (любой активный статус → cancelled).
 * - код причины обязателен; при code=other обязателен свободный текст;
 * - причина сохраняется в тендере (cancellation_reason_code/text) и в ответе;
 * - отмена черновика и опубликованного → 200 cancelled;
 * - без кода → 422; code=other без текста → 422; code=other с текстом → 200;
 * - agent → 403, manager/admin → 200;
 * - чужой/несуществующий → 404; без токена → 401.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderCancelTest extends WebTestCase
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
        return '16.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
            'title' => 'Закупка на отмену',
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

    public function testCancelWithReasonTransitionsToCancelled(): void
    {
        $id = $this->createDraft();
        $this->publish($id);

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'cancellation_needs']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
        self::assertSame('cancellation_needs', $body['cancellation_reason_code']);
    }

    public function testCancelDraftAlsoAllowed(): void
    {
        $id = $this->createDraft();

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'changing_order_conditions']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
        self::assertSame('changing_order_conditions', $body['cancellation_reason_code']);
    }

    public function testCancelWithoutCodeReturns422(): void
    {
        $id = $this->createDraft();

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCancelOtherWithoutTextReturns422(): void
    {
        $id = $this->createDraft();

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'other']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCancelOtherWithTextSucceeds(): void
    {
        $id = $this->createDraft();

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, [
            'cancellation_reason_code' => 'other',
            'cancellation_reason_text' => 'Сняли с плана закупок',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
        self::assertSame('other', $body['cancellation_reason_code']);
        self::assertSame('Сняли с плана закупок', $body['cancellation_reason_text']);
    }

    public function testCancelClosedReturns409(): void
    {
        $id = $this->createDraft();
        $this->publish($id);

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'carrier_refusal']);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'carrier_refusal']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCancelUnknownTenderReturns404(): void
    {
        $url = str_replace('{tenderId}', '00000000-0000-0000-0000-000000000000', TenderCancelController::URL);
        $client = self::request('POST', $url, $this->token, ['cancellation_reason_code' => 'cancellation_needs']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentCannotCancelReturns403(): void
    {
        $id = $this->createDraft();

        $agent = UserFactory::createOne([
            'role' => UserRoleEnum::AGENT,
            'companyId' => $this->company->getId(),
        ]);
        $token = $this->loginAs((string) $agent->getEmail());

        $url = str_replace('{tenderId}', $id, TenderCancelController::URL);
        $client = self::request('POST', $url, $token, ['cancellation_reason_code' => 'cancellation_needs']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $url = str_replace('{tenderId}', '00000000-0000-0000-0000-000000000000', TenderCancelController::URL);
        $client = self::request('POST', $url, '', ['cancellation_reason_code' => 'cancellation_needs']);
        self::assertResponseStatusCodeSame(401);
    }
}
