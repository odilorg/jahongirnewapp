<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_drawer_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('open'); // enum: open, closed
            $table->char('currency', 3)->default('UZS');
            $table->decimal('beginning_saldo', 12, 2)->default(0);
            $table->decimal('expected_end_saldo', 12, 2)->nullable();
            $table->decimal('counted_end_saldo', 12, 2)->nullable();
            $table->decimal('discrepancy', 12, 2)->nullable();
            $table->text('discrepancy_reason')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['cash_drawer_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('opened_at');
            $table->index('closed_at');
            
            // Ensure only one open shift per drawer-user combination
            $table->unique(['cash_drawer_id', 'user_id', 'status'], 'unique_open_shift_per_drawer_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};