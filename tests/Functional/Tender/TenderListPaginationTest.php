<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Tender\Controller\TenderListController;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Пагинация GET /tenders (FR-1.1.1, AR-6, NFR-22): keyset-курсор (limit/cursor),
 * порядок created_at DESC, отсутствие дублей/пропусков между страницами, фильтр
 * status, валидация (422 на невалидные status/limit/cursor).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderListPaginationTest extends WebTestCase
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
        return '12.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
            json_encode(['email' => VerifiedUserStory::EMAIL, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private static function request(string $method, string $url, string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    /**
     * @return array<mixed>
     */
    private static function body(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    public function testCursorPaginationReturnsAllTendersInDescOrder(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tenders = [];
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $title) {
            $tenders[$title] = TenderFactory::createOne([
                'customerId' => $company->getId(),
                'createdBy' => $company->getId(),
                'title' => $title,
            ]);
        }
        $this->setCreatedAt($tenders['P1'], '2026-06-01T00:00:01+00:00');
        $this->setCreatedAt($tenders['P2'], '2026-06-01T00:00:02+00:00');
        $this->setCreatedAt($tenders['P3'], '2026-06-01T00:00:03+00:00');
        $this->setCreatedAt($tenders['P4'], '2026-06-01T00:00:04+00:00');
        $this->setCreatedAt($tenders['P5'], '2026-06-01T00:00:05+00:00');
        $token = self::login();

        $seen = [];
        $titles = [];
        $cursor = null;
        do {
            $url = TenderListController::URL.'?limit=2';
            if (null !== $cursor) {
                $url .= '&cursor='.rawurlencode($cursor);
            }
            $client = self::request('GET', $url, $token);
            self::assertResponseStatusCodeSame(200);
            $body = self::body($client);
            self::assertIsArray($body['items']);

            foreach ($body['items'] as $item) {
                self::assertIsArray($item);
                self::assertIsString($item['id']);
                self::assertNotContains($item['id'], $seen);
                $seen[] = $item['id'];
                self::assertIsString($item['title']);
                $titles[] = $item['title'];
            }
            /** @var string|null $nextCursor */
            $nextCursor = $body['next_cursor'];
            $cursor = $nextCursor;
        } while (null !== $cursor);

        self::assertCount(5, $seen);
        self::assertSame(['P5', 'P4', 'P3', 'P2', 'P1'], $titles);
    }

    public function testStatusFilter(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId(), 'title' => 'Draft']);
        $published = TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId(), 'title' => 'Published']);
        $published->setStatus(TenderStatusEnum::PUBLISHED);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $token = self::login();

        $client = self::request('GET', TenderListController::URL.'?status=published', $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);

        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Published', $body['items'][0]['title']);
        self::assertSame('published', $body['items'][0]['status']);
    }

    public function testDefaultLimitAndClampToMax(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        foreach (['A', 'B', 'C'] as $title) {
            TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId(), 'title' => $title]);
        }
        $token = self::login();

        // limit вне диапазона 1..100 клампется (не 422): 500 → 100, но данных меньше
        $client = self::request('GET', TenderListController::URL.'?limit=500', $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);

        // дефолтный лимит 20 (openapi): без параметра — все 3 в одной странице
        $client = self::request('GET', TenderListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);
    }

    public function testInvalidStatusReturns422(): void
    {
        self::client();
        VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('GET', TenderListController::URL.'?status=bogus', $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidLimitReturns422(): void
    {
        self::client();
        VerifiedUserStory::company();
        $token = self::login();

        $client = self::request('GET', TenderListController::URL.'?limit=abc', $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidCursorReturns422(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);
        $token = self::login();

        $client = self::request('GET', TenderListController::URL.'?limit=2&cursor=!!!bad', $token);
        self::assertResponseStatusCodeSame(422);
    }

    private function setCreatedAt(Tender $tender, string $at): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE App\Tender\Entity\Tender t SET t.createdAt = :at WHERE t.id = :id')
            ->setParameter('at', new \DateTimeImmutable($at, new \DateTimeZone('UTC')))
            ->setParameter('id', $tender->getId())
            ->execute();
    }
}
