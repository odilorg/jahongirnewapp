<?php

namespace Tests\Feature;

use App\Actions\CloseShiftAction;
use App\Actions\RecordTransactionAction;
use App\Actions\StartShiftAction;
use App\Enums\ShiftStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\CashDrawer;
use App\Models\CashCount;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CashierShiftTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;
    protected CashDrawer $drawer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create(['role' => 'cashier']);
        $this->drawer = CashDrawer::factory()->create(['is_active' => true]);
    }

    /** @test */
    public function user_can_start_a_shift()
    {
        $action = new StartShiftAction();
        
        $shift = $action->execute($this->user, $this->drawer, [
            'beginning_saldo' => 100000,
            'currency' => 'UZS',
            'notes' => 'Starting morning shift',
        ]);

        $this->assertInstanceOf(CashierShift::class, $shift);
        $this->assertEquals(ShiftStatus::OPEN, $shift->status);
        $this->assertEquals(100000, $shift->beginning_saldo);
        $this->assertEquals('UZS', $shift->currency);
        $this->assertEquals($this->user->id, $shift->user_id);
        $this->assertEquals($this->drawer->id, $shift->cash_drawer_id);
    }

    /** @test */
    public function user_cannot_start_multiple_shifts_on_same_drawer()
    {
        $action = new StartShiftAction();
        
        // Start first shift
        $action->execute($this->user, $this->drawer, [
            'beginning_saldo' => 100000,
        ]);

        // Try to start second shift
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $action->execute($this->user, $this->drawer, [
            'beginning_saldo' => 50000,
        ]);
    }

    /** @test */
    public function user_can_record_cash_in_transaction()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        $action = new RecordTransactionAction();
        
        $transaction = $action->execute($shift, $this->user, [
            'type' => 'in',
            'amount' => 50000,
            'category' => 'sale',
            'reference' => 'INV-001',
            'notes' => 'Room payment',
        ]);

        $this->assertInstanceOf(CashTransaction::class, $transaction);
        $this->assertEquals(TransactionType::IN, $transaction->type);
        $this->assertEquals(50000, $transaction->amount);
        $this->assertEquals(TransactionCategory::SALE, $transaction->category);
        $this->assertEquals('INV-001', $transaction->reference);
    }

    /** @test */
    public function user_can_record_cash_out_transaction()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        $action = new RecordTransactionAction();
        
        $transaction = $action->execute($shift, $this->user, [
            'type' => 'out',
            'amount' => 15000,
            'category' => 'expense',
            'reference' => 'EXP-001',
            'notes' => 'Office supplies',
        ]);

        $this->assertInstanceOf(CashTransaction::class, $transaction);
        $this->assertEquals(TransactionType::OUT, $transaction->type);
        $this->assertEquals(15000, $transaction->amount);
        $this->assertEquals(TransactionCategory::EXPENSE, $transaction->category);
    }

    /** @test */
    public function user_cannot_record_transaction_on_closed_shift()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::CLOSED,
            'beginning_saldo' => 100000,
        ]);

        $action = new RecordTransactionAction();
        
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $action->execute($shift, $this->user, [
            'type' => 'in',
            'amount' => 50000,
        ]);
    }

    /** @test */
    public function user_can_close_shift_without_discrepancy()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        // Add some transactions
        CashTransaction::factory()->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 50000,
            'created_by' => $this->user->id,
        ]);

        CashTransaction::factory()->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::OUT,
            'amount' => 15000,
            'created_by' => $this->user->id,
        ]);

        $action = new CloseShiftAction();
        
        $closedShift = $action->execute($shift, $this->user, [
            'counted_end_saldo' => 135000,
            'denominations' => [
                ['denomination' => 1000, 'qty' => 50],
                ['denomination' => 5000, 'qty' => 20],
                ['denomination' => 10000, 'qty' => 5],
                ['denomination' => 50000, 'qty' => 1],
            ],
            'notes' => 'Shift closed successfully',
        ]);

        $this->assertEquals(ShiftStatus::CLOSED, $closedShift->status);
        $this->assertEquals(135000, $closedShift->expected_end_saldo);
        $this->assertEquals(135000, $closedShift->counted_end_saldo);
        $this->assertEquals(0, $closedShift->discrepancy);
        $this->assertNotNull($closedShift->closed_at);

        // Check cash count was created
        $this->assertDatabaseHas('cash_counts', [
            'cashier_shift_id' => $shift->id,
            'total' => 135000,
        ]);
    }

    /** @test */
    public function user_can_close_shift_with_discrepancy()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        // Add some transactions
        CashTransaction::factory()->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 50000,
            'created_by' => $this->user->id,
        ]);

        $action = new CloseShiftAction();
        
        $closedShift = $action->execute($shift, $this->user, [
            'counted_end_saldo' => 148000, // 2000 discrepancy
            'denominations' => [
                ['denomination' => 1000, 'qty' => 48],
                ['denomination' => 5000, 'qty' => 20],
                ['denomination' => 10000, 'qty' => 5],
                ['denomination' => 50000, 'qty' => 1],
            ],
            'discrepancy_reason' => 'Found extra cash in drawer',
            'notes' => 'Shift closed with discrepancy',
        ]);

        $this->assertEquals(ShiftStatus::CLOSED, $closedShift->status);
        $this->assertEquals(150000, $closedShift->expected_end_saldo);
        $this->assertEquals(148000, $closedShift->counted_end_saldo);
        $this->assertEquals(-2000, $closedShift->discrepancy);
        $this->assertEquals('Found extra cash in drawer', $closedShift->discrepancy_reason);
    }

    /** @test */
    public function closing_shift_requires_discrepancy_reason_when_there_is_discrepancy()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        $action = new CloseShiftAction();
        
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $action->execute($shift, $this->user, [
            'counted_end_saldo' => 148000, // 2000 discrepancy
            'denominations' => [
                ['denomination' => 1000, 'qty' => 48],
                ['denomination' => 5000, 'qty' => 20],
                ['denomination' => 10000, 'qty' => 5],
                ['denomination' => 50000, 'qty' => 1],
            ],
            // Missing discrepancy_reason
        ]);
    }

    /** @test */
    public function closing_shift_validates_denominations_total()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        $action = new CloseShiftAction();
        
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $action->execute($shift, $this->user, [
            'counted_end_saldo' => 150000,
            'denominations' => [
                ['denomination' => 1000, 'qty' => 50], // Total: 50,000
                ['denomination' => 5000, 'qty' => 20],  // Total: 100,000
                // Total denominations: 150,000 (matches counted_end_saldo)
            ],
        ]);
    }

    /** @test */
    public function shift_calculates_expected_end_saldo_correctly()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::OPEN,
            'beginning_saldo' => 100000,
        ]);

        // Add transactions
        CashTransaction::factory()->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 75000,
            'created_by' => $this->user->id,
        ]);

        CashTransaction::factory()->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::OUT,
            'amount' => 25000,
            'created_by' => $this->user->id,
        ]);

        $expectedSaldo = $shift->calculateExpectedEndSaldo();
        $this->assertEquals(150000, $expectedSaldo); // 100000 + 75000 - 25000
    }

    /** @test */
    public function user_cannot_close_already_closed_shift()
    {
        $shift = CashierShift::factory()->create([
            'user_id' => $this->user->id,
            'cash_drawer_id' => $this->drawer->id,
            'status' => ShiftStatus::CLOSED,
            'beginning_saldo' => 100000,
        ]);

        $action = new CloseShiftAction();
        
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $action->execute($shift, $this->user, [
            'counted_end_saldo' => 100000,
            'denominations' => [
                ['denomination' => 1000, 'qty' => 100],
            ],
        ]);
    }
}