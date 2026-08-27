<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionCancelController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
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

    private Company $customerCompany;
    private User $customerUser;
    private User $agentUser;
    private Company $supplierCompany;
    private User $supplierUser;
    private Tender $tender;
    private Lot $lot;
    private Auction $auction;
    private string $customerToken;
    private string $agentToken;
    private string $supplierToken;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        $this->customerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->customerUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cl-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->agentUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'cl-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplierCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $this->supplierUser = UserFactory::createOne([
            'companyId' => $this->supplierCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cl-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $this->customerCompany->getId(),
        ]);
        $this->lot = LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => self::START_MINOR]);
        $this->auction = AuctionFactory::new()
            ->forTender($this->tender, $this->lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->customerToken = $this->loginAs((string) $this->customerUser->getEmail());
        $this->agentToken = $this->loginAs((string) $this->agentUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $this->supplierUser->getEmail());
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

    private static function cancelUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionCancelController::URL);
    }

    public function testCustomerCancelsNewAuction(): void
    {
        $client = self::request('POST', self::cancelUrl((string) $this->auction->getId()), $this->customerToken, [
            'reason' => 'Больше не требуется',
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
    }

    public function testCustomerCancelsScheduledAuction(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auction = $em->getRepository(Auction::class)->find($this->auction->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $em->flush();

        $client = self::request('POST', self::cancelUrl((string) $this->auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('cancelled', $body['status']);
    }

    public function testCancelTerminalAuctionReturns409(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auction = $em->getRepository(Auction::class)->find($this->auction->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::CANCEL->value);
        $em->flush();

        self::request('POST', self::cancelUrl((string) $this->auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAgentCannotCancel(): void
    {
        self::request('POST', self::cancelUrl((string) $this->auction->getId()), $this->agentToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyCannotCancel(): void
    {
        self::request('POST', self::cancelUrl((string) $this->auction->getId()), $this->supplierToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::request('POST', self::cancelUrl((string) $this->auction->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }
}
