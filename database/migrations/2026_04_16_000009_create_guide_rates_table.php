<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->enum('rate_type', ['per_trip', 'per_day']);
            $table->decimal('cost_usd', 10, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['guide_id', 'is_active']);
        });

        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('guide_rate_id')->nullable()->after('guide_cost')
                ->constrained('guide_rates')->nullOnDelete();
            $table->boolean('guide_cost_override')->default(false)->after('guide_rate_id');
            $table->string('guide_cost_override_reason', 255)->nullable()->after('guide_cost_override');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropForeign(['guide_rate_id']);
            $table->dropColumn(['guide_rate_id', 'guide_cost_override', 'guide_cost_override_reason']);
        });

        Schema::dropIfExists('guide_rates');
    }
};
