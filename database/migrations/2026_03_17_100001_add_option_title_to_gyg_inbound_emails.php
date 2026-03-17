<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->string('option_title', 500)->nullable()->after('tour_name');
        });
    }

    public function down(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->dropColumn('option_title');
        });
    }
};
