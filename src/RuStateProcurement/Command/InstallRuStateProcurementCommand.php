<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Command;

use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Protocol\RuProtocolGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Установка/проверка плагина ru-state-procurement (PL-5, 7.7):
 * - регистрирует auto_generated-типы протоколов (DocumentGenerator, FR-1.2.8);
 * - выводит сводку активных правил из внешней конфигурации.
 *
 * Идемпотентно: повторный запуск не создаёт дубликатов.
 *
 * Запуск: php bin/console ru:procurement:install
 */
#[AsCommand(name: 'ru:procurement:install', description: 'Install ru-state-procurement plugin (document types + rules summary)')]
final class InstallRuStateProcurementCommand extends Command
{
    public function __construct(
        private readonly RuProtocolGenerator $protocols,
        private readonly ProcurementConfig $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->protocols->ensureDocumentTypes();
        $io->success('ru-state-procurement plugin installed: protocol document types registered (auto_generated, FR-1.2.8)');

        $io->section('Активные правила (config/ru_state_procurement.yaml)');
        $io->table(
            ['Правило', 'Значение'],
            [
                ['Приём заявок (аукцион, НМЦК ≤ 30 млн ₽)', $this->config->timelineAuctionDaysMin().' дней'],
                ['Приём заявок (аукцион, НМЦК > 30 млн ₽)', $this->config->timelineAuctionDaysMax().' дней'],
                ['Приём заявок (конкурс)', $this->config->timelineCompetitionDays().' дней'],
                ['Приём заявок (запрос котировок)', $this->config->timelineRfqWorkingDays().' раб. дня'],
                ['Шаг аукциона', $this->config->auctionBidStepMinBps() / 100 .'–'.$this->config->auctionBidStepMaxBps() / 100 .'% НМЦК'],
                ['Время на шаг', $this->config->auctionStepDurationSec().' сек'],
                ['Антиснайпинг', $this->config->auctionExtendOnLastStep() ? 'да (+'.$this->config->auctionExtensionDurationSec().' сек, лимит '.$this->config->auctionMaxExtensions().')' : 'нет'],
                ['Обеспечение заявки', $this->config->securityBidMinBps() / 100 .'–'.$this->config->securityBidMaxBps() / 100 .'% НМЦК'],
                ['Обеспечение исполнения контракта', $this->config->securityContractMinBps() / 100 .'–'.$this->config->securityContractMaxBps() / 100 .'% НМЦК'],
                ['Доменный часовой пояс', $this->config->defaultTimezone()],
            ],
        );

        return Command::SUCCESS;
    }
}
