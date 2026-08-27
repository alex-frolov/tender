<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * CRUD лотов (FR-1.1.7): POST/PATCH/DELETE /tenders/{tenderId}/lots[/{lotId}].
 * - добавление лота (номер следующий по порядку, инвариант суммы);
 * - изменение лота (цена пересчитывается, инвариант суммы);
 * - удаление лота (перенумерация 1..N; последний нельзя);
 * - 409 при нарушении инварианта суммы лотов;
 * - 403 для agent; 401 без токена; 404 для чужого тендера.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderLotsCrudTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $company;
    private Tender $tender;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:9)
        $this->company = VerifiedUserStory::company();
        $this->tender = $this->tenderWithLots(100000, 1);
        $this->token = $this->login();
    }

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

    private function login(string $email = VerifiedUserStory::EMAIL): string
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

    private function tenderWithLots(int $nmckMinor, int $lotCount = 1): Tender
    {
        $tender = TenderFactory::createOne([
            'customerId' => $this->company->getId(),
            'createdBy' => $this->company->getId(),
            'nmckMinor' => $nmckMinor,
        ]);
        for ($i = 1; $i <= $lotCount; ++$i) {
            LotFactory::createOne([
                'tender' => $tender,
                'number' => $i,
                'title' => 'Лот '.$i,
                'priceNetMinor' => 1 === $i ? $nmckMinor : 0,
            ]);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        return $tender;
    }

    public function testAddLot(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), '/api/v1/tenders/{tenderId}/lots');
        // добавляем лот с ценой 0 — сумма не меняется (100000 + 0)
        $client = self::request('POST', $url, $this->token, [
            'title' => 'Новый лот',
            'price_net_minor' => 0,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Новый лот', $body['title']);
        self::assertSame(2, $body['number']);

        // инвариант: сумма лотов = НМЦК
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Tender::class)->find($this->tender->getId());
        self::assertInstanceOf(Tender::class, $fresh);
        self::assertSame(2, $fresh->lotCount());
        self::assertSame(100000, $fresh->lotsSumNetMinor());
    }

    /**
     * FR-1.1.7: НМЦК — производная от лотов (в TenderUpdate её нет), поэтому
     * добавление второго лота с ценой не ломает инвариант, а поднимает НМЦК.
     * Иначе второй лот невозможно было бы добавить в принципе.
     */
    public function testAddLotWithPriceRaisesNmck(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), '/api/v1/tenders/{tenderId}/lots');
        $client = self::request('POST', $url, $this->token, [
            'title' => 'Лот с ценой',
            'price_net_minor' => 50000,
        ]);
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Tender::class)->find($this->tender->getId());
        self::assertInstanceOf(Tender::class, $fresh);
        self::assertSame(2, $fresh->lotCount());
        self::assertSame(150000, $fresh->lotsSumNetMinor());
        self::assertSame(150000, $fresh->getNmckMinor());
    }

    public function testUpdateLot(): void
    {
        $lot = $this->tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        $url = str_replace(['{tenderId}', '{lotId}'], [(string) $this->tender->getId(), (string) $lot->getId()], '/api/v1/tenders/{tenderId}/lots/{lotId}');
        $client = self::request('PATCH', $url, $this->token, [
            'title' => 'Изменённый лот',
            'price_net_minor' => 100000,
            'quantity' => 12.5,
            'unit' => 'кг',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Изменённый лот', $body['title']);
        self::assertSame(100000, $body['price_net_minor']);
        self::assertSame(120000, $body['price_gross_minor']); // 100000 + 20% НДС
        // quantity/unit возвращаются в представлении лота — иначе UI не видит сохранённое значение
        self::assertSame(12.5, $body['quantity']);
        self::assertSame('кг', $body['unit']);
    }

    /**
     * Цена лота меняется свободно: НМЦК пересчитывается как Σ лотов (FR-1.1.7),
     * а не отвергает правку с lots_sum_mismatch.
     */
    public function testUpdateLotPriceRecalculatesNmck(): void
    {
        $lot = $this->tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        $url = str_replace(['{tenderId}', '{lotId}'], [(string) $this->tender->getId(), (string) $lot->getId()], '/api/v1/tenders/{tenderId}/lots/{lotId}');
        $client = self::request('PATCH', $url, $this->token, [
            'price_net_minor' => 99999,
        ]);
        self::assertResponseStatusCodeSame(200);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Tender::class)->find($this->tender->getId());
        self::assertInstanceOf(Tender::class, $fresh);
        self::assertSame(99999, $fresh->getNmckMinor());
    }

    /**
     * Номер лота назначает сервер: присланный в теле number игнорируется,
     * иначе дубликат ронял бы UNIQUE (tender_id, number) в 500.
     */
    public function testAddLotIgnoresClientNumber(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), '/api/v1/tenders/{tenderId}/lots');
        // number=1 уже занят первым лотом — сервер обязан назначить следующий
        $client = self::request('POST', $url, $this->token, [
            'number' => 1,
            'title' => 'Лот с чужим номером',
            'price_net_minor' => 0,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(2, $body['number']);
    }

    public function testDeleteLotRenumbers(): void
    {
        // два лота, сумма = НМЦК (60000 + 40000 = 100000)
        $tender = TenderFactory::createOne([
            'customerId' => $this->company->getId(),
            'createdBy' => $this->company->getId(),
            'nmckMinor' => 100000,
        ]);
        $lotA = LotFactory::createOne([
            'tender' => $tender,
            'number' => 1,
            'title' => 'Лот A',
            'priceNetMinor' => 60000,
        ]);
        $lotB = LotFactory::createOne([
            'tender' => $tender,
            'number' => 2,
            'title' => 'Лот B',
            'priceNetMinor' => 40000,
        ]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $url = str_replace(['{tenderId}', '{lotId}'], [(string) $tender->getId(), (string) $lotA->getId()], '/api/v1/tenders/{tenderId}/lots/{lotId}');
        $client = self::request('DELETE', $url, $this->token);
        self::assertResponseStatusCodeSame(204);

        $em->clear();
        $fresh = $em->getRepository(Tender::class)->find($tender->getId());
        self::assertInstanceOf(Tender::class, $fresh);
        self::assertSame(1, $fresh->lotCount());
        $remaining = $fresh->getLots()->first();
        self::assertInstanceOf(Lot::class, $remaining);
        self::assertSame((string) $lotB->getId(), (string) $remaining->getId());
        self::assertSame(1, $remaining->getNumber());
    }

    public function testDeleteLastLotReturns422(): void
    {
        $lot = $this->tender->getLots()->first();
        self::assertInstanceOf(Lot::class, $lot);

        $url = str_replace(['{tenderId}', '{lotId}'], [(string) $this->tender->getId(), (string) $lot->getId()], '/api/v1/tenders/{tenderId}/lots/{lotId}');
        $client = self::request('DELETE', $url, $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLotCrudOfAnotherTenantReturns404(): void
    {
        $otherTender = TenderFactory::createOne(['customerId' => Uuid::v4(), 'createdBy' => Uuid::v4()]);

        $url = str_replace('{tenderId}', (string) $otherTender->getId(), '/api/v1/tenders/{tenderId}/lots');
        $client = self::request('POST', $url, $this->token, [
            'title' => 'Чужой лот',
            'price_net_minor' => 0,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLotCrudRequiresAuthentication(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), '/api/v1/tenders/{tenderId}/lots');
        $client = self::request('POST', $url, 'invalid-token', [
            'title' => 'Лот',
            'price_net_minor' => 0,
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLotCrudForbiddenForAgent(): void
    {
        $agent = UserFactory::createOne([
            'email' => 'agent-lots@test.loc',
            'role' => UserRoleEnum::AGENT,
            'companyId' => $this->company->getId(),
            'password' => UserFactory::PASSWORD,
        ]);
        self::assertNotNull($agent->getId());

        $token = $this->login('agent-lots@test.loc');

        $url = str_replace('{tenderId}', (string) $this->tender->getId(), '/api/v1/tenders/{tenderId}/lots');
        $client = self::request('POST', $url, $token, [
            'title' => 'Лот агента',
            'price_net_minor' => 0,
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
