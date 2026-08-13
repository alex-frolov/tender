<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Загрузчик конфигурации плагина ru-state-procurement из внешнего YAML-файла
 * (config/ru_state_procurement.yaml). «Конфигурация правил меняется без
 * передеплоя кода (внешние данные)» — критерий готовности плагина (§9
 * domain/plugins/ru-state-procurement.md).
 *
 * Вызывается как фабрика сервиса ProcurementConfig при сборке контейнера
 * (services/ru_state_procurement.yaml): при изменении YAML достаточно
 * cache:clear, код не меняется.
 */
final readonly class ProcurementConfigLoader
{
    public function __construct(
        private string $configFile,
    ) {
    }

    public function load(): ProcurementConfig
    {
        if (!is_file($this->configFile) || !is_readable($this->configFile)) {
            throw new \App\RuStateProcurement\Exception\ProcurementConfigException(\sprintf('Procurement config file not found or not readable: %s', $this->configFile));
        }

        $parsed = Yaml::parseFile($this->configFile);
        if (!\is_array($parsed)) {
            throw new \App\RuStateProcurement\Exception\ProcurementConfigException('Procurement config must contain a rules section');
        }

        /** @var array<string, mixed> $parsed */
        return ProcurementConfig::fromArray($parsed);
    }
}
