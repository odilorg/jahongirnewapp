<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_service_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // e.g. "orient-travel-website"
            $table->string('key_hash', 64)->unique();        // SHA-256 of the API key
            $table->string('key_prefix', 8);                 // First 8 chars for identification in logs
            $table->json('allowed_slugs')->nullable();       // ["cashier","owner-alert"] or null = all
            $table->json('allowed_actions')->nullable();      // ["send-message","get-me"] or null = all
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_service_keys');
    }
};
