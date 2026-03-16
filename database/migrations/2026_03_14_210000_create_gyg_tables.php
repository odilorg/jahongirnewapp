<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maps internal tour products to GYG product IDs
        Schema::create('gyg_products', function (Blueprint $table) {
            $table->id();
            $table->string('gyg_product_id', 100)->unique()->comment('Product ID as known by GYG');
            $table->string('internal_product_id', 100)->nullable()->comment('Internal product reference');
            $table->string('name', 255)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->integer('default_vacancies')->default(10);
            $table->integer('default_cutoff_seconds')->default(3600);
            $table->boolean('is_active')->default(true);
            $table->json('price_categories')->nullable()->comment('Default prices by category in smallest unit');
            $table->timestamps();
        });

        // Availability calendar per product per datetime slot
        Schema::create('gyg_availabilities', function (Blueprint $table) {
            $table->id();
            $table->string('gyg_product_id', 100)->index();
            $table->dateTime('slot_datetime')->comment('ISO 8601 datetime of the availability slot');
            $table->integer('vacancies')->default(0);
            $table->integer('cutoff_seconds')->default(3600);
            $table->string('currency', 10)->default('USD');
            $table->json('prices_by_category')->nullable()->comment('retailPrices array: [{category, price}]');
            $table->json('opening_times')->nullable()->comment('For time period products: [{fromTime, toTime}]');
            $table->timestamps();
            $table->unique(['gyg_product_id', 'slot_datetime'], 'gyg_avail_product_slot_unique');
        });

        // Temporary holds from GYG (before booking confirmed)
        Schema::create('gyg_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_reference', 100)->unique();
            $table->string('gyg_booking_reference', 100)->index();
            $table->string('gyg_product_id', 100)->index();
            $table->dateTime('slot_datetime');
            $table->json('booking_items')->comment('[{category, count}]');
            $table->string('currency', 10)->default('USD');
            $table->string('status', 20)->default('active')->comment('active, cancelled, converted');
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        // Confirmed bookings from GYG
        Schema::create('gyg_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference', 25)->unique()->comment('Max 25 chars per GYG spec');
            $table->string('reservation_reference', 100)->nullable()->index();
            $table->string('gyg_booking_reference', 100)->index();
            $table->string('gyg_activity_reference', 100)->nullable();
            $table->string('gyg_product_id', 100)->index();
            $table->dateTime('slot_datetime');
            $table->json('booking_items')->comment('[{category, count}]');
            $table->json('travelers')->nullable()->comment('Traveler details from GYG');
            $table->json('traveler_hotel')->nullable();
            $table->string('language', 10)->nullable();
            $table->text('comment')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->json('tickets')->nullable()->comment('[{category, ticketCode, ticketCodeType}]');
            $table->string('status', 20)->default('confirmed')->comment('confirmed, cancelled');
            $table->timestamps();
        });

        // Webhook notifications log
        Schema::create('gyg_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 100)->index();
            $table->text('description')->nullable();
            $table->json('payload')->nullable()->comment('Full notification payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gyg_notifications');
        Schema::dropIfExists('gyg_bookings');
        Schema::dropIfExists('gyg_reservations');
        Schema::dropIfExists('gyg_availabilities');
        Schema::dropIfExists('gyg_products');
    }
};
