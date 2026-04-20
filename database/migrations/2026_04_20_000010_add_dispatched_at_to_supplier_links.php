<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $t) {
            $t->timestamp('driver_dispatched_at')->nullable()->after('driver_cost');
            $t->timestamp('guide_dispatched_at')->nullable()->after('guide_cost');
        });

        Schema::table('inquiry_stays', function (Blueprint $t) {
            $t->timestamp('dispatched_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $t) {
            $t->dropColumn(['driver_dispatched_at', 'guide_dispatched_at']);
        });

        Schema::table('inquiry_stays', function (Blueprint $t) {
            $t->dropColumn('dispatched_at');
        });
    }
};
