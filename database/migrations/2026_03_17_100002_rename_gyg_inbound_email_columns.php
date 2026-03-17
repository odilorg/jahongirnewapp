<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->renameColumn('tour_date', 'travel_date');
            $table->renameColumn('tour_time', 'travel_time');
            $table->renameColumn('number_of_guests', 'pax');
            $table->renameColumn('total_price', 'price');
        });
    }

    public function down(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->renameColumn('travel_date', 'tour_date');
            $table->renameColumn('travel_time', 'tour_time');
            $table->renameColumn('pax', 'number_of_guests');
            $table->renameColumn('price', 'total_price');
        });
    }
};
