<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 191)->unique();
            $table->string('user_id', 191)->index();
            $table->string('name');
            $table->string('template_name')->nullable();
            $table->string('message', 160);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_layouts');
    }
};
