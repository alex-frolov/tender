<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Auction\AuctionService;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\JwtService;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WebhookFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Нагрузочные данные для k6 (NFR-1/22): подготавливает в dev-БД
 * состояние для сценариев ставок/каталога/SSE/webhooks и пишет
 * `load/state.json` (токены, id сущностей, hub-JWT), который читают k6-скрипты.
 *
 *   php bin/console app:load:prepare [--suppliers=50] [--catalog=2000] [--webhook-url=...]
 *
 * Создаёт: подтверждённые компании (заказчик + поставщики), пользователей с
 * выпущенными JWT, опубликованный тендер в accepting_bids, аукцион REDUCTION(fixed)
 * в TRADE (rules_snapshot зафиксирован), допущенные заявки, webhook-подписку на
 * auction.bid, каталог тендеров (bulk-insert). Повторный запуск очищает
 * предыдущее состояние (id из state.json + префиксы LOAD-*).
 *
 * Команда dev-only и использует тестовые фабрики (App\Tests\Factory, зарегистрированы
 * в dev через zenstruck_foundry) — в prod недоступна и не вызывается.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:load:prepare', description: 'Prepare k6 load-test state in dev DB (task 7.2)')]
final class LoadPrepareCommand extends Command
{
    private const string START_PRICE_MINOR = '100000000'; // 1 000 000.00 ₽
    private const string STEP_MINOR = '50000';            // 500.00 ₽
    private const string STATE_FILE = 'load/state.json';

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly AuctionService $auctionService,
        private readonly \Redis $redis,
        private readonly CompanyFactory $companyFactory,
        private readonly UserFactory $userFactory,
        private readonly TenderFactory $tenderFactory,
        private readonly LotFactory $lotFactory,
        private readonly AuctionFactory $auctionFactory,
        private readonly BidFactory $bidFactory,
        private readonly WebhookFactory $webhookFactory,
        #[Autowire(service: 'state_machine.tender')]
        private readonly WorkflowInterface $tenderWorkflow,
        #[Autowire(service: 'state_machine.auction')]
        private readonly WorkflowInterface $auctionWorkflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('suppliers', null, InputOption::VALUE_OPTIONAL, 'Number of suppliers', '50')
            ->addOption('catalog', null, InputOption::VALUE_OPTIONAL, 'Number of catalog tenders', '2000')
            ->addOption('webhook-url', null, InputOption::VALUE_OPTIONAL, 'Webhook receiver URL', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->kernel->getEnvironment()) {
            $io->error('app:load:prepare is dev-only (uses test factories)');

            return Command::FAILURE;
        }

        $suppliers = (int) $this->stringOption($input, 'suppliers');
        $catalog = (int) $this->stringOption($input, 'catalog');
        $webhookUrl = $this->webhookUrl($input);

        $this->cleanup($io);

        $state = $this->seed($suppliers, $catalog, $webhookUrl, $io);
        $this->writeState($state);

        $io->success(\sprintf(
            'Load state ready: %d suppliers, auction %s in TRADE, catalog %d tenders, webhook %s',
            $suppliers,
            $state['auction']['id'],
            $catalog,
            $state['webhook']['id'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{run_id: string, base_url: string, api_base: string,
     *   auction: array{id: string, lot_id: string, start_price_minor: int, step_minor: int, supplier_index: int},
     *   auctions: list<array{id: string, lot_id: string, start_price_minor: int, step_minor: int, supplier_index: int}>,
     *   tender: array{id: string}, lot_id: string,
     *   customer: array{company_id: string, user_id: string, token: string},
     *   suppliers: list<array{company_id: string, user_id: string, token: string}>,
     *   webhook: array{id: string, url: string},
     *   hub: array{url: string, topic: string, publish_token: string, subscribe_token: string},
     *   catalog: array{count: int}}
     */
    private function seed(int $suppliers, int $catalog, string $webhookUrl, SymfonyStyle $io): array
    {
        // ── Заказчик + суперадмин-пользователь компании ──
        $customer = $this->companyFactory->new([
            'type' => CompanyTypeEnum::CUSTOMER,
            'legalName' => 'LOAD Customer',
        ])->approved()->create();
        $customerUser = $this->userFactory->new([
            'role' => UserRoleEnum::ADMIN,
            'companyId' => $customer->getId(),
            'email' => 'load-customer-'.bin2hex(random_bytes(4)).'@load.test',
            'name' => 'Load Customer Admin',
        ])->create();
        $customerToken = $this->issueToken($customerUser);

        // ── Поставщики ──
        $supplierEntries = [];
        for ($i = 1; $i <= $suppliers; ++$i) {
            $company = $this->companyFactory->new([
                'type' => CompanyTypeEnum::SUPPLIER,
                'legalName' => 'LOAD Supplier '.$i,
            ])->approved()->create();
            $user = $this->userFactory->new([
                'role' => UserRoleEnum::ADMIN,
                'companyId' => $company->getId(),
                'email' => 'load-supplier-'.$i.'-'.bin2hex(random_bytes(4)).'@load.test',
                'name' => 'Load Supplier '.$i,
            ])->create();
            $supplierEntries[] = [
                'company_id' => (string) $company->getId(),
                'user_id' => (string) $user->getId(),
                'token' => $this->issueToken($user),
            ];
        }

        // ── Тендер в accepting_bids (FR-1.1.4): publish → start_bid_acceptance ──
        $tender = $this->tenderFactory->new([
            'title' => 'LOAD-TENDER load auction',
            'customerId' => $customer->getId(),
            'createdBy' => $customerUser->getId(),
            'nmckMinor' => (int) self::START_PRICE_MINOR,
        ])->create();
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->em->flush();

        // ── N аукционов REDUCTION(fixed) в TRADE (NFR-1: «суммарно по всем
        //    активным аукционам»). На один аукцион — один допущенный поставщик:
        //    параллельные ставки разных VU не сериализуются на pessimistic-lock
        //    одной строки (FR-1.3.6), замеряется чистый write-путь. PR-9:
        //    rules_snapshot фиксируется startTrading. ──
        /** @var list<array{id: string, lot_id: string, start_price_minor: int, step_minor: int, supplier_index: int}> $auctions */
        $auctions = [];
        foreach ($supplierEntries as $index => $entry) {
            $lot = $this->lotFactory->new([
                'tender' => $tender,
                'number' => $index + 1,
                'title' => 'LOAD lot '.($index + 1),
                'priceNetMinor' => (int) self::START_PRICE_MINOR,
            ])->create();
            $auction = $this->auctionFactory->new()
                ->forTender($tender, $lot)
                ->with([
                    'type' => AuctionTypeEnum::REDUCTION,
                    'stepMode' => AuctionStepModeEnum::FIXED,
                    'bidStepMinor' => (int) self::STEP_MINOR,
                    'stepDurationSec' => 600,
                ])
                ->create();
            $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
            $this->auctionService->startTrading($auction);

            // Допущенная заявка поставщика (FR-1.2.4) на свой аукцион.
            $this->bidFactory->new()
                ->forAuction($auction, Uuid::fromString($entry['company_id']))
                ->admitted()
                ->create();

            $auctions[] = [
                'id' => (string) $auction->getId(),
                'lot_id' => (string) $lot->getId(),
                'start_price_minor' => (int) self::START_PRICE_MINOR,
                'step_minor' => (int) self::STEP_MINOR,
                'supplier_index' => $index,
            ];
        }
        $primaryAuction = $auctions[0];

        // ── Webhook-подписка на auction.bid (WH-7): доставка в load-receiver ──
        $webhook = $this->webhookFactory->new([
            'tenantId' => $customer->getId(),
            'url' => $webhookUrl,
            'events' => ['auction.bid'],
        ])->create();

        // ── Каталог тендеров (NFR-22): bulk-insert опубликованных тендеров ──
        $this->seedCatalog($catalog, $customer->getId(), $customerUser->getId());

        // ── Hub-JWT для SSE-сценария (NFR-22): тестовый topic load:{runId} ──
        $runId = Uuid::v4();
        $topic = 'load:'.(string) $runId;
        $hub = [
            'url' => $this->env('MERCURE_PUBLIC_URL', 'http://localhost:3008/.well-known/mercure'),
            'topic' => $topic,
            // publish-claim — только '*': в Mercure 0.x glob-паттерны в claims не
            // поддерживаются (load:* → 401); subscribe — точный topic.
            'publish_token' => $this->signHubToken(
                $this->env('MERCURE_JWT_SECRET_PUBLISH', ''),
                ['mercure' => ['publish' => ['*']]],
            ),
            'subscribe_token' => $this->signHubToken(
                $this->env('MERCURE_JWT_SECRET_SUBSCRIBE', ''),
                ['mercure' => ['subscribe' => [$topic]]],
            ),
        ];

        return [
            'run_id' => (string) $runId,
            'base_url' => $this->env('LOAD_BASE_URL', 'http://localhost:8080'),
            'api_base' => '/api/v1',
            'auction' => $primaryAuction,
            'auctions' => $auctions,
            'tender' => ['id' => (string) $tender->getId()],
            'lot_id' => $primaryAuction['lot_id'],
            'customer' => [
                'company_id' => (string) $customer->getId(),
                'user_id' => (string) $customerUser->getId(),
                'token' => $customerToken,
            ],
            'suppliers' => $supplierEntries,
            'webhook' => ['id' => (string) $webhook->getId(), 'url' => $webhookUrl],
            'hub' => $hub,
            'catalog' => ['count' => $catalog],
        ];
    }

    /**
     * Bulk-insert тендеров каталога (NFR-22): один INSERT через generate_series.
     * Скоуп нагрузки — «фильтры на большом каталоге»: сеем `$count` тендеров,
     * из них CATALOG_PUBLISHED — published (рабочий набор доски, фильтр
     * ?status=published), остальные — closed (масштаб каталога). Лотов нет —
     * агрегация статусов возвращает статус тендера (Tender::aggregateStatus с []).
     */
    private const int CATALOG_PUBLISHED = 100;

    private function seedCatalog(int $count, Uuid $tenantId, Uuid $createdBy): void
    {
        if ($count <= 0) {
            return;
        }
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO tenders
                (id, tenant_id, number, title, procedure_type, law_type, nmck_minor,
                 no_start_price, currency, vat_rate_bps, price_basis, customer_id,
                 access_type, status, created_by, created_at, updated_at)
             SELECT gen_random_uuid(), :tenant, \'LOAD-CAT-\' || i, \'LOAD-CATALOG-\' || i,
                    \'auction\', \'commercial\', 1000000000, false, \'RUB\', 2000, \'net\',
                    :tenant, \'open\',
                    CASE WHEN i <= :published THEN \'published\' ELSE \'closed\' END,
                    :createdBy, NOW(), NOW()
             FROM generate_series(1, :count) AS i',
            [
                'tenant' => (string) $tenantId,
                'createdBy' => (string) $createdBy,
                'count' => $count,
                'published' => min($count, self::CATALOG_PUBLISHED),
            ],
            [
                'tenant' => Types::STRING,
                'createdBy' => Types::STRING,
                'count' => Types::INTEGER,
                'published' => Types::INTEGER,
            ],
        );
    }

    private function cleanup(SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();

        $companyIds = $conn->fetchFirstColumn("SELECT id FROM companies WHERE legal_name LIKE 'LOAD %'");
        if ([] === $companyIds) {
            return;
        }

        $tenderIds = $conn->fetchFirstColumn(
            'SELECT id FROM tenders WHERE tenant_id IN (:ids)',
            ['ids' => $companyIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $auctionIds = [] !== $tenderIds
            ? $conn->fetchFirstColumn(
                'SELECT id FROM auctions WHERE tender_id IN (:ids)',
                ['ids' => $tenderIds],
                ['ids' => ArrayParameterType::STRING],
            )
            : [];

        foreach ($auctionIds as $auctionId) {
            \assert(\is_string($auctionId));
            $this->redis->del('auction:state:'.$auctionId, 'auction:heartbeat:'.$auctionId);
        }
        if ([] !== $auctionIds) {
            $conn->executeStatement('DELETE FROM auction_bids WHERE auction_id IN (:ids)', ['ids' => $auctionIds], ['ids' => ArrayParameterType::STRING]);
            $conn->executeStatement('DELETE FROM auctions WHERE id IN (:ids)', ['ids' => $auctionIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $tenderIds) {
            $conn->executeStatement('DELETE FROM bids WHERE tender_id IN (:ids)', ['ids' => $tenderIds], ['ids' => ArrayParameterType::STRING]);
            $conn->executeStatement('DELETE FROM lots WHERE tender_id IN (:ids)', ['ids' => $tenderIds], ['ids' => ArrayParameterType::STRING]);
            $conn->executeStatement('DELETE FROM tenders WHERE id IN (:ids)', ['ids' => $tenderIds], ['ids' => ArrayParameterType::STRING]);
        }
        $conn->executeStatement('DELETE FROM webhooks WHERE tenant_id IN (:ids)', ['ids' => $companyIds], ['ids' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM users WHERE company_id IN (:ids)', ['ids' => $companyIds], ['ids' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM companies WHERE id IN (:ids)', ['ids' => $companyIds], ['ids' => ArrayParameterType::STRING]);

        $io->writeln('<info>Previous load state cleaned up (markers LOAD-*).</info>');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $file = $this->kernel->getProjectDir().'/'.self::STATE_FILE;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o775, true);
        }
        file_put_contents($file, json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    private function issueToken(User $user): string
    {
        return $this->jwt->issue(
            $user->getId(),
            $user->getCompanyId(),
            $user->getRole()->value,
            (string) Uuid::v4(),
        )['token'];
    }

    /**
     * Hub-JWT (HS256) для SSE-нагрузки: publisher — на topic load:*, subscriber —
     * на конкретный load:{runId}. Подписывается MERCURE_* секретами (те же, что
     * у хаба в docker-compose). Время жизни — 12 часов.
     *
     * @param array<string, mixed> $claims
     */
    private function signHubToken(string $secret, array $claims): string
    {
        if ('' === $secret) {
            throw new \RuntimeException('Mercure JWT secret is not configured (MERCURE_JWT_SECRET_PUBLISH/SUBSCRIBE)');
        }
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token = $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+12 hours'))
            ->withClaim('mercure', $claims['mercure'])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (null === $value || !\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Option "%s" must be a string', $name));
        }

        return $value;
    }

    private function webhookUrl(InputInterface $input): string
    {
        $value = $input->getOption('webhook-url');
        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        return $this->env('LOAD_WEBHOOK_URL', 'http://loadreceiver:8787/hook');
    }
}
