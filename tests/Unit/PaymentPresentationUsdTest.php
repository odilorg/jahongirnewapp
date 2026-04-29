<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\PaymentPresentation;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Regression for fix/cashier-bot-allow-usd-collection.
 *
 * Before the fix, presentedAmountFor('USD') threw InvalidArgumentException
 * even though usdPresented was already on the DTO. The cashier bot's
 * currency keyboard correspondingly omitted the USD button — so a hotel
 * cashier could not record a USD-cash room payment via Telegram at all.
 */
class PaymentPresentationUsdTest extends TestCase
{
    private function makeDto(int $usdPresented = 67): PaymentPresentation
    {
        return new PaymentPresentation(
            beds24BookingId:         'B-USD-1',
            syncId:                  1,
            dailyExchangeRateId:     1,
            guestName:               'John Doe',
            arrivalDate:             '2026-04-29',
            uzsPresented:            820_000,
            eurPresented:            60,
            rubPresented:            6_200,
            fxRateDate:              '29.04.2026',
            botSessionId:            'sess-1',
            presentedAt:             Carbon::now(),
            usdPresented:            $usdPresented,
        );
    }

    public function test_presented_amount_for_usd_returns_usd_presented(): void
    {
        $dto = $this->makeDto(usdPresented: 67);
        $this->assertSame(67.0, $dto->presentedAmountFor('USD'));
    }

    public function test_presented_amount_for_usd_handles_lowercase(): void
    {
        $dto = $this->makeDto(usdPresented: 67);
        $this->assertSame(67.0, $dto->presentedAmountFor('usd'));
    }

    public function test_presented_amount_for_zero_usd_returns_zero(): void
    {
        // A booking with no USD pricing returns 0 (caller is responsible for
        // hiding the button when usdPresented <= 0; the DTO itself does not
        // throw on the zero case).
        $dto = $this->makeDto(usdPresented: 0);
        $this->assertSame(0.0, $dto->presentedAmountFor('USD'));
    }

    public function test_unsupported_currency_still_throws(): void
    {
        $dto = $this->makeDto();
        $this->expectException(\InvalidArgumentException::class);
        $dto->presentedAmountFor('JPY');
    }

    public function test_existing_currencies_still_work(): void
    {
        $dto = $this->makeDto();
        $this->assertSame(820_000.0, $dto->presentedAmountFor('UZS'));
        $this->assertSame(60.0,      $dto->presentedAmountFor('EUR'));
        $this->assertSame(6_200.0,   $dto->presentedAmountFor('RUB'));
    }
}
