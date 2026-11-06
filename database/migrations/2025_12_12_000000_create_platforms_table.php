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
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nome da plataforma (OpenAI, Claude, etc.)
            $table->string('slug')->unique(); // Slug para identificação (openai, claude, etc.)
            $table->json('models'); // Array de modelos disponíveis
            $table->string('status')->default('active'); // active, inactive, maintenance

            // Colunas adicionais sugeridas
            $table->string('api_base_url')->nullable(); // URL base da API
            $table->boolean('api_key_required')->default(true); // Se requer chave de API
            $table->json('rate_limits')->nullable(); // Limites de taxa em JSON
            $table->json('supported_features')->nullable(); // Recursos suportados (chat, image, audio, etc.)
            $table->text('description')->nullable(); // Descrição da plataforma
            $table->boolean('is_active')->default(true); // Se está ativa no sistema
            $table->string('logo_url')->nullable(); // URL do logo da plataforma
            $table->json('pricing')->nullable(); // Informações de preço por modelo
            $table->string('documentation_url')->nullable(); // URL da documentação
            $table->integer('priority')->default(0); // Ordem de prioridade na interface

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
