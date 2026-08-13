<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionCancelController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Отмена аукциона (POST /auctions/{id}/cancel, → CANCELLED).
 *
 * - заказчик (admin) отменяет аукцион в new → 200, status=cancelled;
 * - отмена запланированного (scheduled) → 200;
 * - повторная отмена терминального → 409 (state_transition_forbidden);
 * - agent → 403; чужой тенант → 404; не аутентифицированный → 401.
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionCancelTest extends WebTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

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
        return '71.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{customerToken: string, agentToken: string,
     *               supplierToken: string, auction: Auction}
     */
    private static function customerAuction(): array
    {
        self::client();
        $container = self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cl-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'cl-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cl-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'agentToken' => self::loginAs((string) $agent->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'auction' => $auction,
        ];
    }

    private static function cancelUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionCancelController::URL);
    }

    public function testCustomerCancelsNewAuction(): void
    {
        $ctx = self::customerAuction();

        $client = self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'reason' => 'Больше не требуется',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
    }

    public function testCustomerCancelsScheduledAuction(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auction = $em->getRepository(Auction::class)->find($ctx['auction']->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $em->flush();

        $client = self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
    }

    public function testCancelTerminalAuctionReturns409(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auction = $em->getRepository(Auction::class)->find($ctx['auction']->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::CANCEL->value);
        $em->flush();

        self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAgentCannotCancel(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), $ctx['agentToken']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyCannotCancel(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::cancelUrl((string) $ctx['auction']->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }
}
