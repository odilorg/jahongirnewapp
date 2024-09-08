<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('fathers_name')->nullable();
            $table->string('email')->nullable();            
            $table->string('phone01');
            $table->string('phone02')->nullable();
            $table->text('extra_info')->nullable();
            $table->string('school')->nullable();
            $table->string('lang_spoken');
            $table->string('image');
            $table->string('passport_image')->nullable();
            $table->string('full_name')->virtualAs('concat(first_name, \' \', last_name, \'\', fathers_name)');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
