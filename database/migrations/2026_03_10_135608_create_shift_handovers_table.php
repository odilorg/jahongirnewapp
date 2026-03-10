<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_shift_id')->constrained('cashier_shifts');
            $table->foreignId('incoming_shift_id')->nullable()->constrained('cashier_shifts');
            $table->string('cash_photo_path')->nullable();
            $table->decimal('counted_uzs', 12, 2)->default(0);
            $table->decimal('counted_usd', 12, 2)->default(0);
            $table->decimal('counted_eur', 12, 2)->default(0);
            $table->decimal('expected_uzs', 12, 2)->default(0);
            $table->decimal('expected_usd', 12, 2)->default(0);
            $table->decimal('expected_eur', 12, 2)->default(0);
            $table->unsignedBigInteger('incoming_user_id')->nullable();
            $table->timestamp('incoming_confirmed_at')->nullable();
            $table->text('discrepancy_notes')->nullable();
            $table->timestamp('owner_notified_at')->nullable();
            $table->timestamps();

            $table->foreign('incoming_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_handovers');
    }
};
