<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderGetController;
use App\Tender\Controller\TenderPublishController;
use App\Tender\Controller\TenderUpdateController;
use App\Tender\Entity\Tender;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E тендеров: сквозной сценарий
 * создание (черновик) → публикация → изменение.
 *
 * Покрывает FR-1.1.1 (CRUD + черновик), FR-1.1.4 (публикация + таймлайн),
 * FR-1.1.7 (инвариант суммы лотов при создании/публикации). После публикации
 * тендер остаётся редактируемым до окончания приёма заявок (assertEditable).
 *
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 */
final class TenderE2EFlowTest extends WebTestCase
{
    private const EMAIL = VerifiedUserStory::EMAIL;
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
        return '16.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function login(): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => self::EMAIL, 'password' => VerifiedUserStory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
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

    /**
     * @return array<string, mixed>
     */
    private static function createPayload(string $companyId): array
    {
        return [
            'title' => 'E2E закупка ИТ-оборудования',
            'description' => 'Описание закупки',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => $companyId,
            'region' => 'Москва',
            'access_type' => 'open',
            'lots' => [
                ['title' => 'Серверы', 'price_net_minor' => 60000],
                ['title' => 'СХД', 'price_net_minor' => 40000],
            ],
        ];
    }

    public function testCreatePublishModifyFlow(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $token = self::login();

        // 1. создание черновика с лотами и инвариантом суммы (FR-1.1.7)
        $client = self::request('POST', TenderCreateController::URL, $token, self::createPayload((string) $company->getId()));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('draft', $body['status']);
        self::assertIsString($body['id']);
        $id = $body['id'];

        // 2. публикация → published + таймлайн (FR-1.1.4)
        $url = str_replace('{tenderId}', $id, TenderPublishController::URL);
        $client = self::request('POST', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $published = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($published);
        self::assertSame('published', $published['status']);
        self::assertIsArray($published['timeline']);
        self::assertArrayHasKey('bids_start', $published['timeline']);
        self::assertArrayHasKey('bids_end', $published['timeline']);
        self::assertIsString($published['timeline']['bids_start']);
        self::assertIsString($published['timeline']['bids_end']);

        // 3. изменение после публикации (до окончания приёма заявок) — FR-1.1.1
        $url = str_replace('{tenderId}', $id, TenderUpdateController::URL);
        $client = self::request('PATCH', $url, $token, [
            'title' => 'E2E закупка (изменена)',
            'region' => 'Санкт-Петербург',
            'change_reason' => 'Уточнение требований после публикации',
        ]);
        self::assertResponseStatusCodeSame(200);
        $updated = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertSame('E2E закупка (изменена)', $updated['title']);
        self::assertSame('published', $updated['status']);

        // 4. состояние в БД согласовано с API
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tender = $em->getRepository(Tender::class)->find($id);
        self::assertInstanceOf(Tender::class, $tender);
        self::assertSame('E2E закупка (изменена)', $tender->getTitle());
        self::assertSame('Санкт-Петербург', $tender->getRegion());
        self::assertSame('published', $tender->getStatus()->value);
        self::assertIsArray($tender->getTimeline());
        self::assertArrayHasKey('bids_end', $tender->getTimeline());

        // 5. карточка через GET возвращает актуальные данные
        $url = str_replace('{tenderId}', $id, TenderGetController::URL);
        $client = self::request('GET', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $single = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($single);
        self::assertSame($id, $single['id']);
        self::assertSame('E2E закупка (изменена)', $single['title']);
        self::assertSame('published', $single['status']);
    }
}
