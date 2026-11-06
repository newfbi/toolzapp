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
        Schema::create('user_list', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->unique(); // Identificador público do contato
            $table->string('user_id', 191)->index(); // Dono da lista (users.uuid)
            $table->string('name'); // Nome do lead
            $table->string('email', 191)->nullable()->index(); // E-mail do lead
            $table->string('phone', 30)->nullable()->index(); // Telefone/WhatsApp (somente dígitos)
            $table->string('status')->default('active')->index(); // active, inactive
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_list');
    }
};
