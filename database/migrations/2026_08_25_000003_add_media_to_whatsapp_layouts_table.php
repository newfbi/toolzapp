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
        Schema::table('whatsapp_layouts', function (Blueprint $table) {
            // Tipo de mídia anexa à mensagem (null = somente texto, 'image' = imagem)
            $table->string('media_type', 20)->nullable()->after('message');
            // Origem da mídia: caminho relativo no disco público ou URL externa (http/https)
            $table->string('media_source', 500)->nullable()->after('media_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_layouts', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'media_source']);
        });
    }
};
