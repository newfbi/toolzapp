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
        Schema::create('whatsapp_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->unique(); // Identificador público do layout
            $table->string('user_id', 191)->index(); // Dono do layout (users.uuid)
            $table->string('name'); // Nome de identificação do layout
            $table->text('message'); // Conteúdo da mensagem (suporta variáveis {{nome}})
            $table->string('status')->default('active')->index(); // active, inactive
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_layouts');
    }
};
