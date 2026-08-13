<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuStateProcurement;

use App\Contract\Entity\Enum\SecurityKindEnum;
use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Rules\RuSecurityRules;
use PHPUnit\Framework\TestCase;

/**
 * Правила обеспечения по 44-ФЗ (плагин ru-state-procurement): заявка 0,5–5%
 * НМЦК, исполнение контракта 5–30% НМЦК.
 */
final class RuSecurityRulesTest extends TestCase
{
    public function testBidSecurityRange(): void
    {
        $rules = new RuSecurityRules(ProcurementConfig::fromArray([]));

        self::assertSame(['min_bps' => 50, 'max_bps' => 500], $rules->percentRange(SecurityKindEnum::BID));
    }

    public function testContractSecurityRange(): void
    {
        $rules = new RuSecurityRules(ProcurementConfig::fromArray([]));

        self::assertSame(['min_bps' => 500, 'max_bps' => 3000], $rules->percentRange(SecurityKindEnum::CONTRACT));
    }

    public function testConfigValuesAreUsed(): void
    {
        $rules = new RuSecurityRules(ProcurementConfig::fromArray([
            'security' => ['bid_min_bps' => 100, 'contract_max_bps' => 4000],
        ]));

        self::assertSame(['min_bps' => 100, 'max_bps' => 500], $rules->percentRange(SecurityKindEnum::BID));
        self::assertSame(['min_bps' => 500, 'max_bps' => 4000], $rules->percentRange(SecurityKindEnum::CONTRACT));
    }
}
