<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\ExportJobProcessor;
use App\Export\ExportRowSourceRegistry;
use App\Export\Repository\ExportJobRepository;
use App\Export\Storage\ExportFileStorage;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Infrastructure\Metrics\ExportMetricsCollector;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ExportJobFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * потоковая генерация файлов экспорта (UC-31, AM-15, NFR-18).
 *
 * ExportJobProcessor пишет xlsx/csv построчно через OpenSpout (файл не
 * собирается в памяти) и переводит задачу queued → processing → ready/failed:
 * - CSV: заголовок + строки, UTF-8 BOM, содержимое из источника тендеров;
 * - XLSX: валидный zip-архив (workbook.xml + данные листа);
 * - идемпотентность повторной доставки (ready — no-op);
 * - ошибка генерации → статус failed + error + outbox export.failed.
 *
 * Файлы пишутся на диск (var/exports) — чистим в tearDown; БД откатывается
 * dama/doctrine-test-bundle.
 */
final class ExportJobProcessorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ExportJobProcessor $processor;
    private ExportJobRepository $jobs;
    private ExportFileStorage $storage;
    private AuditService $audit;
    private LoggerInterface $logger;

    /** @var list<string> созданные файлы для очистки в tearDown */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->jobs = $container->get(ExportJobRepository::class);
        $this->storage = $container->get(ExportFileStorage::class);
        $this->audit = $container->get(AuditService::class);
        $this->logger = $container->get(LoggerInterface::class);

        $this->processor = new ExportJobProcessor(
            em: $this->em,
            jobs: $this->jobs,
            sources: $container->get(ExportRowSourceRegistry::class),
            storage: $this->storage,
            audit: $this->audit,
            logger: $this->logger,
            exportMetrics: $container->get(ExportMetricsCollector::class),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            $this->storage->delete($path);
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    public function testCsvExportStreamsRowsAndMarksReady(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        TenderFactory::createOne(['customerId' => $company->getId(), 'title' => 'CSV export target']);
        $job = ExportJobFactory::createOne([
            'tenantId' => $company->getId(),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::CSV,
        ]);

        $this->processor->process((string) $job->getId());

        $this->em->clear();
        $updated = $this->jobs->findById((string) $job->getId());
        self::assertNotNull($updated);
        self::assertSame(ExportJobStatusEnum::READY, $updated->getStatus());
        self::assertNotNull($updated->getStoragePath());
        self::assertNotNull($updated->getFileName());
        self::assertGreaterThan(0, $updated->getFileSize());
        self::assertNotNull($updated->getCompletedAt());

        $content = $this->storage->read($updated->getStoragePath());
        // UTF-8 BOM (OpenSpout CSV) + заголовок + данные тендера.
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        self::assertStringContainsString('id,number,title,status', $content);
        self::assertStringContainsString('CSV export target', $content);

        $storagePath = $updated->getStoragePath();
        $this->createdFiles[] = $storagePath;
    }

    public function testXlsxExportProducesValidWorkbook(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        TenderFactory::createOne(['customerId' => $company->getId(), 'title' => 'XLSX export target']);
        $job = ExportJobFactory::createOne([
            'tenantId' => $company->getId(),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::XLSX,
        ]);

        $this->processor->process((string) $job->getId());

        $this->em->clear();
        $updated = $this->jobs->findById((string) $job->getId());
        self::assertNotNull($updated);
        self::assertSame(ExportJobStatusEnum::READY, $updated->getStatus());
        self::assertNotNull($updated->getStoragePath());

        $content = $this->storage->read($updated->getStoragePath());
        self::assertStringStartsWith('PK', $content);

        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $content);
        self::assertTrue($zip->open($tmp));
        self::assertNotFalse($zip->locateName('xl/workbook.xml'));

        $text = '';
        foreach (['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $text .= (string) $zip->getFromName($part);
        }
        self::assertStringContainsString('XLSX export target', $text);
        $zip->close();
        @unlink($tmp);

        $storagePath = $updated->getStoragePath();
        $this->createdFiles[] = $storagePath;
    }

    public function testReprocessingReadyJobIsNoop(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $job = ExportJobFactory::createOne([
            'tenantId' => $company->getId(),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::CSV,
        ]);

        $this->processor->process((string) $job->getId());
        $this->em->clear();
        $updated = $this->jobs->findById((string) $job->getId());
        self::assertNotNull($updated);
        self::assertSame(ExportJobStatusEnum::READY, $updated->getStatus());
        $size = $updated->getFileSize();

        // Повторная доставка (retry) не перегенерирует файл.
        $this->processor->process((string) $job->getId());
        $this->em->clear();
        $reloaded = $this->jobs->findById((string) $job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ExportJobStatusEnum::READY, $reloaded->getStatus());
        self::assertSame($size, $reloaded->getFileSize());

        $storagePath = $updated->getStoragePath();
        self::assertNotNull($storagePath);
        $this->createdFiles[] = $storagePath;
    }

    public function testGenerationFailureMarksFailedWithError(): void
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $job = ExportJobFactory::createOne([
            'tenantId' => $company->getId(),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::CSV,
        ]);

        // Хранилище в несуществующем/недоступном каталоге → mkdir бросает
        // IOException → задача переводится в failed (catch в processor).
        $badStorage = new ExportFileStorage('/proc/nonexistent-exports', new Filesystem());

        $processor = new ExportJobProcessor(
            em: $this->em,
            jobs: $this->jobs,
            sources: self::getContainer()->get(ExportRowSourceRegistry::class),
            storage: $badStorage,
            audit: $this->audit,
            logger: $this->logger,
            exportMetrics: self::getContainer()->get(ExportMetricsCollector::class),
        );
        $processor->process((string) $job->getId());

        $this->em->clear();
        $updated = $this->jobs->findById((string) $job->getId());
        self::assertNotNull($updated);
        self::assertSame(ExportJobStatusEnum::FAILED, $updated->getStatus());
        self::assertNotNull($updated->getError());
        self::assertNull($updated->getStoragePath());

        // outbox export.failed зафиксирован (алерт, domain/events.md).
        $events = $this->em->getRepository(OutboxEvent::class)->findBy([
            'eventType' => 'export.failed',
            'aggregateId' => (string) $job->getId(),
        ]);
        self::assertCount(1, $events);
    }

    public function testUnknownJobIsIgnored(): void
    {
        $missingId = (string) Uuid::v4();
        $this->processor->process($missingId);

        // Несуществующая задача не порождает записей и не бросает исключений.
        $this->em->clear();
        self::assertNull($this->jobs->findById($missingId));
    }
}
