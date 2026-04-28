<?php

declare(strict_types=1);

namespace Tests\Unit\CashierBot;

use App\Http\Controllers\CashierBotController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression guard for Phase A1 (Microphase-7 dead-code removal).
 *
 * If any of these names reappear, a future merge has reintroduced the legacy
 * non-FX payment path. The FX-presentation flow (BotPaymentService::preparePayment)
 * is the only sanctioned route from `selectGuest` to `confirmPayment`.
 */
final class LegacyMethodsRemovedTest extends TestCase
{
    /**
     * @dataProvider deletedMethodsProvider
     */
    public function test_legacy_method_stays_removed(string $methodName): void
    {
        $r = new ReflectionClass(CashierBotController::class);
        $this->assertFalse(
            $r->hasMethod($methodName),
            "Legacy method `{$methodName}` was reintroduced into CashierBotController. "
            . 'See tests/Unit/CashierBot/LegacyMethodsRemovedTest.php for context.'
        );
    }

    public static function deletedMethodsProvider(): array
    {
        return [
            ['hPayAmt'],
            ['askExchangeRate'],
            ['hPayRate'],
            ['acceptReferenceRate'],
        ];
    }

    public function test_exchange_rate_service_no_longer_injected(): void
    {
        $r = new ReflectionClass(CashierBotController::class);
        $this->assertFalse(
            $r->hasProperty('fxRateService'),
            'CashierBotController::$fxRateService was reintroduced. ExchangeRateService should not be injected here.'
        );
    }
}
