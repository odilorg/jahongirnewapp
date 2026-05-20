<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to 2026_05_20_120000: `address_city` was also NOT NULL with
 * no default, blocking Turfirma create when the upstream record didn't
 * provide city as a separate field (didox returns one `address` string
 * and we map it to `address_street`).
 *
 * Auditing the full table after the first migration showed `address_city`
 * was the only remaining blocker. Operator can fill the city later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('turfirmas', function (Blueprint $table) {
            $table->string('address_city')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('turfirmas', function (Blueprint $table) {
            $table->string('address_city')->nullable(false)->default('')->change();
        });
    }
};
