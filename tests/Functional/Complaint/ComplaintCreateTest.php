<?php

declare(strict_types=1);

namespace App\Tests\Functional\Complaint;

use App\Complaint\Controller\ComplaintCreateController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Жалобы по тендеру (FR-1.2.10): POST /tenders/{tenderId}/complaints.
 *
 * - POST: участник с правом tenders.qa; status=pending; document_ids — приложения;
 * - Валидация: text и ground обязательны (422).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class ComplaintCreateTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

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
        return '88.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
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

    private static function loginAs(string $email): string
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
     * @return array{token: string, tenderId: string}
     */
    private static function complaintContext(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'cp-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $tender = TenderFactory::createOne([
            'customerId' => $customer->getId(),
            'createdBy' => $customer->getId(),
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'tenderId' => (string) $tender->getId()];
    }

    public function testFileComplaint(): void
    {
        self::client();
        $ctx = self::complaintContext();
        $url = str_replace('{tenderId}', (string) $ctx['tenderId'], ComplaintCreateController::URL);

        $client = self::request('POST', $url, $ctx['token'], [
            'text' => 'Не допускают до участия',
            'ground' => 'Нарушение порядка подачи заявок',
            'document_ids' => [(string) Uuid::v4()],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $ctx['tenderId'], $body['tender_id']);
        self::assertSame('pending', $body['status']);
        self::assertSame('Не допускают до участия', $body['text']);
        self::assertSame('Нарушение порядка подачи заявок', $body['ground']);
        /** @var list<string> $documents */
        $documents = $body['document_ids'];
        self::assertCount(1, $documents);
    }

    public function testFileComplaintRequiresTextAndGround(): void
    {
        self::client();
        $ctx = self::complaintContext();
        $url = str_replace('{tenderId}', (string) $ctx['tenderId'], ComplaintCreateController::URL);

        $client = self::request('POST', $url, $ctx['token'], ['text' => '']);
        self::assertResponseStatusCodeSame(422);
    }
}
