<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\CashierExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierExchangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashierExchangeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashierExchangeService();
    }

    private function createOpenShift(): CashierShift
    {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user = \App\Models\User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function test_creates_both_in_and_out_transactions(): void
    {
        $shift = $this->createOpenShift();

        $ref = $this->service->recordExchange($shift->id, [
            'in_amount' => 100,
            'in_currency' => 'USD',
            'out_amount' => 1280000,
            'out_currency' => 'UZS',
        ], $shift->user_id);

        $this->assertStringStartsWith('EX-', $ref);

        $txns = CashTransaction::where('reference', $ref)->get();
        $this->assertCount(2, $txns);

        // CashTransaction.type is cast to TransactionType enum — firstWhere
        // uses strict comparison, so the raw string 'in'/'out' never matches
        // the enum object. Use the enum constants.
        $inTx = $txns->firstWhere('type', TransactionType::IN);
        $outTx = $txns->firstWhere('type', TransactionType::OUT);

        // Both currency and related_currency on CashTransaction are cast to
        // the Currency enum. Compare on ->value so the assertions are stable
        // across the cast.
        $this->assertEquals(100, $inTx->amount);
        $this->assertSame('USD', $inTx->currency->value);
        $this->assertSame('UZS', $inTx->related_currency->value);
        $this->assertEquals(1280000, $inTx->related_amount);

        $this->assertEquals(1280000, $outTx->amount);
        $this->assertSame('UZS', $outTx->currency->value);
        $this->assertSame('USD', $outTx->related_currency->value);
        $this->assertEquals(100, $outTx->related_amount);
    }

    public function test_rejects_exchange_on_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift closed during confirmation');

        $this->service->recordExchange($shift->id, [
            'in_amount' => 100, 'in_currency' => 'USD',
            'out_amount' => 1280000, 'out_currency' => 'UZS',
        ], $shift->user_id);
    }

    public function test_no_transactions_written_when_shift_closed(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        try {
            $this->service->recordExchange($shift->id, [
                'in_amount' => 100, 'in_currency' => 'USD',
                'out_amount' => 1280000, 'out_currency' => 'UZS',
            ], $shift->user_id);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertEquals(0, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_both_transactions_roll_back_on_failure(): void
    {
        $shift = $this->createOpenShift();

        try {
            DB::transaction(function () use ($shift) {
                CashTransaction::create([
                    'cashier_shift_id' => $shift->id, 'type' => 'in',
                    'amount' => 100, 'currency' => 'USD', 'category' => 'exchange',
                    'reference' => 'EX-ROLLBACK',
                    'created_by' => $shift->user_id, 'occurred_at' => now(),
                ]);

                throw new \RuntimeException('Simulated failure before out-leg');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertEquals(0, CashTransaction::where('reference', 'EX-ROLLBACK')->count());
    }

    public function test_succeeds_callback_inside_transaction(): void
    {
        $shift = $this->createOpenShift();
        $callbackId = 'cb_ex_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_exchange',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        $this->service->recordExchange($shift->id, [
            'in_amount' => 50, 'in_currency' => 'EUR',
            'out_amount' => 640000, 'out_currency' => 'UZS',
        ], $shift->user_id, $callbackId);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'succeeded',
        ]);
    }

    public function test_callback_stays_processing_on_rejected_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        $callbackId = 'cb_ex_reject_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_exchange',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        try {
            $this->service->recordExchange($shift->id, [
                'in_amount' => 50, 'in_currency' => 'EUR',
                'out_amount' => 640000, 'out_currency' => 'UZS',
            ], $shift->user_id, $callbackId);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'processing',
        ]);
    }
}
