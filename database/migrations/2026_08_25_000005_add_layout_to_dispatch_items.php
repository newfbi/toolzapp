<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adiciona layout_id nos itens da fila para permitir deduplicação
     * por (layout × contato): quem já recebeu um layout não recebe de novo,
     * mas continua elegível para layouts diferentes.
     */
    public function up(): void
    {
        Schema::table('whatsapp_dispatch_items', function (Blueprint $table) {
            $table->string('layout_id', 191)->nullable()->after('dispatch_id')->index();
        });

        // Backfill: itens existentes herdam o layout do disparo pai.
        // A forma JOIN abaixo é específica do MySQL; o banco de testes SQLite
        // não possui itens legados para backfill.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'UPDATE whatsapp_dispatch_items i
                 INNER JOIN whatsapp_dispatches d ON d.id = i.dispatch_id
                 SET i.layout_id = d.layout_id'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_dispatch_items', function (Blueprint $table) {
            $table->dropIndex(['layout_id']);
            $table->dropColumn('layout_id');
        });
    }
};
