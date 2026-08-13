<?php

declare(strict_types=1);

namespace App\RuStateProcurement\DependencyInjection;

use App\Auction\Rules\AuctionRules;
use App\Contract\Rules\SecurityRules;
use App\RuStateProcurement\Rules\RuAuctionRules;
use App\RuStateProcurement\Rules\RuSecurityRules;
use App\RuStateProcurement\Rules\RuTimelineRules;
use App\Tender\Timeline\TimelineRules;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass активации правил плагина ru-state-procurement (PL-1/PL-8, 7.7).
 *
 * «Подключение/отключение плагина без изменений ядра (feature-flag,
 * конфигурация)» — критерий готовности §9 domain/plugins/ru-state-procurement.md:
 * при PROCUREMENT_PLUGIN_ENABLED=1 алиасы контрактов правил ядра
 * (TimelineRules/AuctionRules/SecurityRules) переключаются на реализации
 * плагина («РФ-правила активны»); при 0 контракты остаются на дефолтах ядра.
 * Ядро при этом не меняется — только DI-конфигурация.
 *
 * Алиасы уже заданы в services/{tender,auction,contract}.yaml → базовые
 * реализации; pass выполняется после загрузки определений и переопределяет их.
 * Значение feature-flag читается напрямую из окружения (env-плейсхолдер в
 * параметре %procurement_plugin_enabled% на момент pass'а ещё не разрешён —
 * ResolveEnvPlaceholdersPass выполняется позже).
 */
final readonly class ProcurementRulesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$this->enabled()) {
            return;
        }

        $container->setAlias(TimelineRules::class, RuTimelineRules::class)->setPublic(false);
        $container->setAlias(AuctionRules::class, RuAuctionRules::class)->setPublic(false);
        $container->setAlias(SecurityRules::class, RuSecurityRules::class)->setPublic(false);
    }

    /**
     * Значение feature-flag PROCUREMENT_PLUGIN_ENABLED (1/true/on/yes — включено).
     */
    private function enabled(): bool
    {
        $value = getenv('PROCUREMENT_PLUGIN_ENABLED');
        if (false === $value) {
            $env = $_ENV['PROCUREMENT_PLUGIN_ENABLED'] ?? $_SERVER['PROCUREMENT_PLUGIN_ENABLED'] ?? null;
            $value = \is_string($env) ? $env : '';
        }

        return \in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
