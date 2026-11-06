<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices usados pelos fluxos de atendimento, CRM e WhatsApp.
     *
     * A base legada não possuía índices nas chaves de relacionamento das
     * conversas. Com o crescimento do histórico, cada abertura de chat e cada
     * atualização em tempo real passava a varrer a tabela inteira.
     */
    public function up(): void
    {
        $indexes = [
            'agents' => [
                'user_id' => 'agents_user_id_index',
            ],
            'chats' => [
                'uuid' => 'chats_uuid_index',
                'user_id' => 'chats_user_id_index',
                'agent_id' => 'chats_agent_id_index',
                'updated_at' => 'chats_updated_at_index',
            ],
            'messages' => [
                'chat_id' => 'messages_chat_id_index',
                'agent_id' => 'messages_agent_id_index',
                'created_at' => 'messages_created_at_index',
            ],
            'memory_streams' => [
                'agent_id' => 'memory_streams_agent_id_index',
                'user_id' => 'memory_streams_user_id_index',
            ],
        ];

        foreach ($indexes as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table))
                ->pluck('name')
                ->all();

            foreach ($columns as $column => $indexName) {
                if (!Schema::hasColumn($table, $column) || in_array($indexName, $existing, true)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
                    $blueprint->index($column, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        $indexes = [
            'agents' => ['agents_user_id_index'],
            'chats' => ['chats_uuid_index', 'chats_user_id_index', 'chats_agent_id_index', 'chats_updated_at_index'],
            'messages' => ['messages_chat_id_index', 'messages_agent_id_index', 'messages_created_at_index'],
            'memory_streams' => ['memory_streams_agent_id_index', 'memory_streams_user_id_index'],
        ];

        foreach ($indexes as $table => $indexNames) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getIndexes($table))
                ->pluck('name')
                ->all();

            foreach ($indexNames as $indexName) {
                if (in_array($indexName, $existing, true)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                        $blueprint->dropIndex($indexName);
                    });
                }
            }
        }
    }
};
