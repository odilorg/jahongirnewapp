<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('scheduled_messages', function (Blueprint $table) {
        $table->unsignedBigInteger('booking_id')->nullable()->after('id');
        // Optional: Add a foreign key constraint if desired
        $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
    });
}

public function down()
{
    Schema::table('scheduled_messages', function (Blueprint $table) {
        $table->dropForeign(['booking_id']);
        $table->dropColumn('booking_id');
    });
}
};
