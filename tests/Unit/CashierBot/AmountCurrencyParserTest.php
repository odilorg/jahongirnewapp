<?php

namespace Tests\Unit\CashierBot;

use App\Http\Controllers\CashierBotController;
use Tests\TestCase;

/**
 * Regression: parseAmountCurrency MUST never silently default to UZS
 * when input contains a currency token. Foreign-currency expenses had
 * been silently mis-recorded as UZS for forms like "20EUR" / "EUR 20"
 * because the parser fell through to UZS instead of asking the user
 * to clarify.
 *
 * Financial-integrity rule: bare numeric ("50000") → UZS is OK.
 * Anything else with an unrecognised token → return null currency
 * so the caller re-prompts.
 */
class AmountCurrencyParserTest extends TestCase
{
    private function parse(string $input): array
    {
        $controller = app(CashierBotController::class);
        $reflect    = new \ReflectionMethod($controller, 'parseAmountCurrency');
        $reflect->setAccessible(true);
        return $reflect->invoke($controller, $input);
    }

    // ── Valid EUR forms ───────────────────────────────────────────────

    public static function eur_data_provider(): array
    {
        return [
            'space suffix EUR'      => ['20 EUR',   20,  'EUR'],
            'no-space suffix EUR'   => ['20EUR',    20,  'EUR'],
            'lowercase eur'         => ['20 eur',   20,  'EUR'],
            'no-space lowercase'    => ['20eur',    20,  'EUR'],
            'prefix EUR space'      => ['EUR 20',   20,  'EUR'],
            'symbol prefix'         => ['€20',      20,  'EUR'],
            'symbol suffix'         => ['20€',      20,  'EUR'],
            'symbol suffix space'   => ['20 €',     20,  'EUR'],
            'word suffix евро'      => ['20 евро',  20,  'EUR'],
            'word suffix no-space'  => ['20евро',   20,  'EUR'],
            'thousand-sep amount'   => ['1,500 EUR', 1500, 'EUR'],
            'space thousand-sep'    => ['1 500 EUR', 1500, 'EUR'],
        ];
    }

    /**
     * @dataProvider eur_data_provider
     * @test
     */
    public function eur_input_resolves_to_eur(string $input, float $expectedAmt, string $expectedCur): void
    {
        [$amt, $cur] = $this->parse($input);
        $this->assertEquals($expectedAmt, $amt, "EUR amount for `{$input}`");
        $this->assertSame($expectedCur, $cur,  "EUR currency for `{$input}`");
    }

    // ── Valid USD forms ───────────────────────────────────────────────

    /** @test */
    public function usd_forms_all_resolve(): void
    {
        $cases = [
            ['20 USD',  20, 'USD'],
            ['20USD',   20, 'USD'],   // no space
            ['USD 20',  20, 'USD'],   // prefix
            ['$20',     20, 'USD'],
            ['20$',     20, 'USD'],
            ['20 $',    20, 'USD'],
            ['20 долларов', 20, 'USD'],
            ['20долларов',  20, 'USD'],
        ];
        foreach ($cases as [$in, $expAmt, $expCur]) {
            [$amt, $cur] = $this->parse($in);
            $this->assertSame((float) $expAmt, (float) $amt, "amount for `{$in}`");
            $this->assertSame($expCur, $cur, "currency for `{$in}`");
        }
    }

    // ── RUB forms ─────────────────────────────────────────────────────

    /** @test */
    public function rub_forms_resolve(): void
    {
        foreach ([['1500 RUB',1500],['1500RUB',1500],['1500₽',1500],['₽1500',1500],['1500 руб',1500],['1500руб',1500]] as [$in,$expAmt]) {
            [$amt, $cur] = $this->parse($in);
            $this->assertSame((float) $expAmt, (float) $amt, "amount for `{$in}`");
            $this->assertSame('RUB', $cur, "currency for `{$in}`");
        }
    }

    // ── UZS forms ─────────────────────────────────────────────────────

    /** @test */
    public function uzs_forms_resolve(): void
    {
        foreach ([['50000', 50000], ['50000 UZS', 50000], ['50000UZS', 50000], ['50000 сум', 50000]] as [$in,$expAmt]) {
            [$amt, $cur] = $this->parse($in);
            $this->assertSame((float) $expAmt, (float) $amt, "amount for `{$in}`");
            $this->assertSame('UZS', $cur, "currency for `{$in}`");
        }
    }

    /** @test */
    public function bare_numeric_defaults_to_uzs(): void
    {
        [$amt, $cur] = $this->parse('50000');
        $this->assertSame(50000.0, $amt);
        $this->assertSame('UZS', $cur);
    }

    /** @test */
    public function comma_thousand_separator_is_not_silently_truncated(): void
    {
        // BUG that motivated this test: "1,000,000" used to parse as 1
        // because str_replace(',', '.') made it "1.000.000" → floatval=1.
        // Now: comma-as-thousand handled correctly.
        [$amt, $cur] = $this->parse('1,000,000');
        $this->assertSame(1_000_000.0, $amt, 'Million with comma thousand-sep must not collapse to 1');
        $this->assertSame('UZS', $cur);
    }

    /** @test */
    public function comma_as_decimal_is_supported(): void
    {
        [$amt, $cur] = $this->parse('20,5 EUR');
        $this->assertSame(20.5, $amt);
        $this->assertSame('EUR', $cur);
    }

    // ── Invalid / ambiguous → null currency, NOT silent UZS ───────────

    /** @test */
    public function unrecognised_token_returns_null_currency_not_uzs(): void
    {
        $cases = ['20EU', '20US', 'EU 20', '20 д', '20XYZ'];
        foreach ($cases as $in) {
            [, $cur] = $this->parse($in);
            $this->assertNull($cur, "Unrecognised token `{$in}` MUST return null currency, not UZS fallback");
        }
    }

    /** @test */
    public function empty_input_returns_null_currency(): void
    {
        [$amt, $cur] = $this->parse('   ');
        $this->assertSame(0.0, $amt);
        $this->assertNull($cur);
    }
}
