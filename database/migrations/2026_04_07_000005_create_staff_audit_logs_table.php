<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable audit log for driver and guide mutations.
 *
 * Intentionally no FK constraint on entity_id — audit rows must survive
 * a hard delete of the referenced entity. The entity_type + entity_id
 * pair is enough context to reconstruct what happened.
 *
 * No updated_at — this is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 20)->comment('driver | guide');
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30)->comment('created | updated | activated | deactivated | deleted');
            $table->json('changes')->nullable()->comment('{"field":{"old":X,"new":Y}} or snapshot for create/delete');
            $table->string('actor', 100)->nullable()->comment('telegram_user_id or system identifier');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'sal_entity_lookup');
            $table->index('created_at', 'sal_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_audit_logs');
    }
};
