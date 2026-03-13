<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_issues', function (Blueprint $table) {
            $table->string('voice_path')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('room_issues', function (Blueprint $table) {
            $table->dropColumn('voice_path');
        });
    }
};
