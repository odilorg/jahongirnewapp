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
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->onDelete('cascade');
            $table->string('type'); // enum: in, out
            $table->decimal('amount', 12, 2);
            $table->string('category')->nullable(); // enum: sale, refund, expense, deposit, change, other
            $table->string('reference')->nullable(); // invoice_id, booking_id, etc.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index('cashier_shift_id');
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};