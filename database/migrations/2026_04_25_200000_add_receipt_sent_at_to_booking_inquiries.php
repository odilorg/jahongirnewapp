<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('receipt_sent_at')->nullable()->after('paid_at')
                ->comment('First successful guest receipt dispatch (email or WhatsApp). Idempotency guard — never reset, use force flag for manual resend.');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('receipt_sent_at');
        });
    }
};
