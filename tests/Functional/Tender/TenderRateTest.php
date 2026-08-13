<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderRateController;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 5.9: оценка исполнения (FR-1.1.10, UC-10c, POST /tenders/{id}/rating).
 *
 * - только после завершения исполнения (тендер CLOSED) — иначе 409
 *   {rating_not_allowed};
 * - оценка 1..10 (вне диапазона — 422);
 * - права: tenders.rate (admin/manager; agent → 403);
 * - оценка сохраняется в тендере (execution_rating).
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class TenderRateTest extends WebTestCase
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
        return '35.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    /**
     * Тендер с завершённым аукционом (победитель выбран) и закрытым лотом.
     *
     * @return array{customer: \App\Iam\Entity\Company, customerToken: string,
     *               agentToken: string, auction: Auction}
     */
    private static function closedTenderContext(): array
    {
        self::client();
        $container = self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'rate-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'rate-agent-'.random_int(1000, 999999).'@test.ru',
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

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }

        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $auctionService->startTrading($auction);
        $supplierId = Uuid::v4();
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
        $winnerService->selectWinnerAutomatic($auction);

        // Закрываем лот (имитация DONE: execution закрывает лот).
        $em = $container->get(EntityManagerInterface::class);
        $closedLot = $em->getRepository(\App\Tender\Entity\Lot::class)->find($auction->getLotId());
        $closedLot?->setStatus(\App\Tender\Entity\Enum\LotStatusEnum::CLOSED);
        $em->flush();

        return [
            'customer' => $customer,
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'agentToken' => self::loginAs((string) $agent->getEmail()),
            'auction' => $auction,
        ];
    }

    private static function rateUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, TenderRateController::URL);
    }

    public function testRateAfterExecutionDone(): void
    {
        $ctx = self::closedTenderContext();
        $tenderId = (string) $ctx['auction']->getTenderId();

        $client = self::request('POST', self::rateUrl($tenderId), $ctx['customerToken'], ['execution_rating' => 9]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(9, $body['execution_rating']);
    }

    public function testRateBeforeDoneReturns409(): void
    {
        $ctx = self::closedTenderContext();
        $auction = $ctx['auction'];
        $tender = $auction->getTenderId();

        // Открываем лот обратно (тендер не завершён) → rating_not_allowed.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $freshLot = $em->getRepository(\App\Tender\Entity\Lot::class)->find($auction->getLotId());
        $freshLot?->setStatus(\App\Tender\Entity\Enum\LotStatusEnum::BIDDING);
        $em->flush();
        $em->clear();

        $client = self::request('POST', self::rateUrl((string) $tender), $ctx['customerToken'], ['execution_rating' => 8]);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('rating_not_allowed', $body['code']);
    }

    public function testRateOutOfRangeReturns422(): void
    {
        $ctx = self::closedTenderContext();
        $tenderId = (string) $ctx['auction']->getTenderId();

        $client = self::request('POST', self::rateUrl($tenderId), $ctx['customerToken'], ['execution_rating' => 11]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCannotRateReturns403(): void
    {
        $ctx = self::closedTenderContext();
        $tenderId = (string) $ctx['auction']->getTenderId();

        self::request('POST', self::rateUrl($tenderId), $ctx['agentToken'], ['execution_rating' => 9]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $ctx = self::closedTenderContext();
        $tenderId = (string) $ctx['auction']->getTenderId();

        self::request('POST', self::rateUrl($tenderId), '', ['execution_rating' => 9]);
        self::assertResponseStatusCodeSame(401);
    }
}
