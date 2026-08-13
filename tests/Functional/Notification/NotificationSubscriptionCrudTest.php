<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Notification\Controller\NotificationSubscriptionCreateController;
use App\Notification\Controller\NotificationSubscriptionDeleteController;
use App\Notification\Controller\NotificationSubscriptionListController;
use App\Notification\Controller\NotificationSubscriptionToggleController;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\NotificationSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Задача 6.6: подписки на уведомления — CRUD через API (FR-1.6, AM-11).
 *
 * - Создание (POST /notifications/subscriptions): channel/events/filters/digest;
 * - Список (GET): только свои подписки;
 * - Toggle (POST /{id}/toggle): active true↔false;
 * - Удаление (DELETE ?subscriptionId=): 204;
 * - Валидация: неверный канал/пустые события/формат событий → 422;
 * - Права (FR-1.6.3): notifications.subscribe — common, доступен всем ролям
 *   (в т.ч. agent); 401 без токена; чужая подписка → 404.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class NotificationSubscriptionCrudTest extends WebTestCase
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
        return '44.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{token: string, user: object, company: object}
     */
    private static function actor(UserRoleEnum $role): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => $role,
            'email' => 'notif-'.$role->value.'-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'user' => $user, 'company' => $company];
    }

    public function testFullLifecycleCreateListToggleDelete(): void
    {
        self::client();
        $ctx = self::actor(UserRoleEnum::ADMIN);
        $token = $ctx['token'];

        // Создание.
        $client = self::request('POST', NotificationSubscriptionCreateController::URL, $token, [
            'channel' => 'email',
            'events' => ['tender.published', 'bid.qualified'],
            'filters' => ['region' => 'msk'],
            'digest' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $subscriptionId = $body['id'];
        self::assertIsString($subscriptionId);
        self::assertSame('email', $body['channel']);
        self::assertSame(['tender.published', 'bid.qualified'], $body['events']);
        self::assertSame(['region' => 'msk'], $body['filters']);
        self::assertTrue($body['digest']);
        self::assertTrue($body['active']);
        self::assertIsString($body['created_at']);

        // Список — подписка видна.
        $client = self::request('GET', NotificationSubscriptionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        $first = $list['items'][0];
        self::assertIsArray($first);
        self::assertSame($subscriptionId, $first['id']);

        // Toggle → active=false.
        $client = self::request('POST', str_replace('{subscriptionId}', $subscriptionId, NotificationSubscriptionToggleController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertFalse($body['active']);

        // Toggle обратно → active=true.
        $client = self::request('POST', str_replace('{subscriptionId}', $subscriptionId, NotificationSubscriptionToggleController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['active']);

        // Удаление → 204, список пуст.
        $client = self::request('DELETE', NotificationSubscriptionDeleteController::URL.'?subscriptionId='.$subscriptionId, $token);
        self::assertResponseStatusCodeSame(204);

        $client = self::request('GET', NotificationSubscriptionListController::URL, $token);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertSame([], $list['items']);
    }

    public function testCreateValidatesChannelEventsAndFilters(): void
    {
        self::client();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        // Неверный канал.
        $client = self::request('POST', NotificationSubscriptionCreateController::URL, $token, [
            'channel' => 'sms',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Пустые события.
        $client = self::request('POST', NotificationSubscriptionCreateController::URL, $token, [
            'channel' => 'email',
            'events' => [],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Неверный формат события.
        $client = self::request('POST', NotificationSubscriptionCreateController::URL, $token, [
            'channel' => 'email',
            'events' => ['Tender.Published'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCanManageOwnSubscriptions(): void
    {
        // notifications.subscribe — common, включён всем ролям по умолчанию (FR-1.6.3).
        self::client();
        $token = self::actor(UserRoleEnum::AGENT)['token'];

        $client = self::request('POST', NotificationSubscriptionCreateController::URL, $token, [
            'channel' => 'email',
            'events' => ['tender.published'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $client = self::request('GET', NotificationSubscriptionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request('GET', NotificationSubscriptionListController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testForeignSubscriptionReturns404(): void
    {
        self::client();
        $other = NotificationSubscriptionFactory::createOne();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        $client = self::request('POST', str_replace('{subscriptionId}', (string) $other->getId(), NotificationSubscriptionToggleController::URL), $token);
        self::assertResponseStatusCodeSame(404);

        $client = self::request('DELETE', NotificationSubscriptionDeleteController::URL.'?subscriptionId='.(string) $other->getId(), $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteForeignSubscriptionReturns404(): void
    {
        self::client();
        $other = NotificationSubscriptionFactory::createOne();
        $token = self::actor(UserRoleEnum::ADMIN)['token'];

        $client = self::request('DELETE', NotificationSubscriptionDeleteController::URL.'?subscriptionId='.(string) $other->getId(), $token);
        self::assertResponseStatusCodeSame(404);
    }
}
