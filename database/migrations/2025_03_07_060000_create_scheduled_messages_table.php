<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration was added after the fact to make RefreshDatabase work in tests.
     * The scheduled_messages table already exists in production (created outside of
     * migrations), so the `hasTable` guard prevents it from running again there.
     */
    public function up(): void
    {
        if (Schema::hasTable('scheduled_messages')) {
            return;
        }

        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('frequency')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
