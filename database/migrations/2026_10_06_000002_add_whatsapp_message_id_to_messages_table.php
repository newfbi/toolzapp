<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages')) {
            return;
        }

        if (!Schema::hasColumn('messages', 'whatsapp_message_id')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->string('whatsapp_message_id', 64)->nullable();
            });
        }

        // O banco legado usa MyISAM/utf8mb4 e não comporta uma chave composta
        // longa com agent_id. O lock Laravel + esta coluna indexada garantem a
        // deduplicação sem alterar a estrutura histórica da tabela.
        $indexExists = false;
        foreach (Schema::getIndexes('messages') as $index) {
            if (($index['name'] ?? null) === 'messages_whatsapp_message_id_index') {
                $indexExists = true;
                break;
            }
        }

        if (!$indexExists) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->index('whatsapp_message_id', 'messages_whatsapp_message_id_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages') || !Schema::hasColumn('messages', 'whatsapp_message_id')) {
            return;
        }

        $indexExists = false;
        foreach (Schema::getIndexes('messages') as $index) {
            if (($index['name'] ?? null) === 'messages_whatsapp_message_id_index') {
                $indexExists = true;
                break;
            }
        }

        Schema::table('messages', function (Blueprint $table) use ($indexExists): void {
            if ($indexExists) {
                $table->dropIndex('messages_whatsapp_message_id_index');
            }
            $table->dropColumn('whatsapp_message_id');
        });
    }
};
