<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            return;
        }

        Schema::create('messages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('agent_id');
            $table->string('chat_id');
            $table->string('name');
            $table->text('content')->nullable();
            $table->float('credit_usage')->default(0);
            $table->string('direction')->default('put');
            $table->string('phone')->nullable();
            $table->string('audio')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
