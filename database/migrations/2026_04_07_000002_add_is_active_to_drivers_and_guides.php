<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_active boolean to drivers and guides so inactive staff can be hidden
 * from the booking assignment UI without deleting the record.
 *
 * Also make guide_image nullable: the ops bot creates guides without a photo,
 * and the image is purely cosmetic (used by the web front-end).
 *
 * All existing rows default to is_active = true (no disruption to live data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('telegram_chat_id');
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('telegram_chat_id');
            $table->string('guide_image')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn('is_active');
            $table->string('guide_image')->nullable(false)->change();
        });
    }
};
