<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp lead-candidate review queue. The scanner surfaces inbound DM
 * prospects (not already in the CRM) here as `pending`; an operator confirms
 * (-> creates a booking_inquiry) or dismisses. We NEVER auto-create inquiries
 * from WhatsApp because there is no reliable template/sender signal (unlike the
 * Gmail contact form). Idempotent on the normalized phone. Additive table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_lead_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 32)->unique();         // normalized digits — idempotency key
            $table->text('first_inbound')->nullable();     // first guest message (trimmed)
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('inbound_count')->default(0);
            $table->unsignedInteger('outbound_count')->default(0);
            $table->string('status', 16)->default('pending'); // pending | created | dismissed
            $table->foreignId('booking_inquiry_id')->nullable()
                ->constrained('booking_inquiries')->nullOnDelete();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_lead_candidates');
    }
};
