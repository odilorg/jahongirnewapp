<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('scheduled_message_chat', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scheduled_message_id')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->timestamps();

            // Foreign keys commented out - tables don't exist yet
            // $table->foreign('scheduled_message_id')
            //       ->references('id')
            //       ->on('scheduled_messages')
            //       ->onDelete('cascade');

            // $table->foreign('chat_id')
            //       ->references('id')
            //       ->on('chats')
            //       ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('scheduled_message_chat');
    }
};
