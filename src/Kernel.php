<?php

declare(strict_types=1);

namespace App;

use App\RuStateProcurement\DependencyInjection\ProcurementRulesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Активация правил плагина ru-state-procurement (feature-flag
        // PROCUREMENT_PLUGIN_ENABLED, PL-1/PL-8, задача 7.7).
        $container->addCompilerPass(new ProcurementRulesPass());
    }
}
