<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_processing_log', function (Blueprint $table) {
            $table->id();
            $table->string('email_message_id')->index();
            $table->string('email_from');
            $table->string('email_subject');
            $table->timestamp('email_date');
            $table->enum('action', ['fetched', 'filtered', 'extracted', 'stored', 'failed'])->index();
            $table->enum('status', ['success', 'error'])->index();
            $table->json('details')->nullable();
            $table->integer('processing_time_ms')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_processing_log');
    }
};
