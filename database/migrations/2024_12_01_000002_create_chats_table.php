<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chats')) {
            return;
        }

        Schema::create('chats', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid');
            $table->string('user_id')->nullable();
            $table->string('agent_id');
            $table->string('selected_model')->nullable();
            $table->string('contact_lid', 255)->nullable();
            $table->string('phone')->nullable();
            $table->string('name');
            $table->string('conversion')->nullable();
            $table->string('received_prod')->default('false');
            $table->string('time')->nullable();
            $table->text('last_message')->nullable();
            $table->string('status')->default('active');
            $table->string('is_pause')->default('false');
            $table->timestamps();

            $table->index('uuid');
            $table->index('user_id');
            $table->index('agent_id');
            $table->index('selected_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
