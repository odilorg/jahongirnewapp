<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3 — Accommodation supplier model.
 *
 * Mirror of drivers / guides: a normalised supplier table that operators
 * pick from when assigning lodging to a booking inquiry. Uses free-text
 * type / location for v1 (no enum / FK to a place catalog yet).
 *
 * telegram_chat_id is reserved for Phase 5.4 (auto-dispatch via stored
 * chat_id rather than phone lookup) — unused today.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('accommodations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('type', 32)->nullable();          // yurt | homestay | hotel | guesthouse
            $table->string('location', 191)->nullable();     // free text "Aydarkul Lake"
            $table->string('contact_name', 191)->nullable(); // manager name
            $table->string('phone_primary', 64)->nullable();
            $table->string('phone_secondary', 64)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('telegram_chat_id', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodations');
    }
};
