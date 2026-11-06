<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agentes criados pelo Chat Pessoal podiam ser reutilizados pela tela de
     * instâncias porque ambos usavam a mesma chave model_default. Agentes já
     * vinculados a uma instância WhatsApp precisam operar como atendimento,
     * não como agente pessoal de mensagens para si mesmo.
     */
    public function up(): void
    {
        if (!Schema::hasTable('agents') || !Schema::hasTable('whatsapp_instances')) {
            return;
        }

        $agentIds = DB::table('whatsapp_instances')
            ->whereNotNull('agent_id')
            ->where('agent_id', '!=', '')
            ->distinct()
            ->pluck('agent_id');

        if ($agentIds->isEmpty()) {
            return;
        }

        $updates = ['is_personal' => false];
        if (Schema::hasColumn('agents', 'channel')) {
            $updates['channel'] = 'whatsapp';
        }

        DB::table('agents')
            ->whereIn('uuid', $agentIds->all())
            ->update($updates);
    }

    /**
     * Não reverte automaticamente esses campos: o estado anterior pode ter
     * sido compartilhado por mais de um fluxo e não é possível inferi-lo com
     * segurança após a migração.
     */
    public function down(): void
    {
        // Intencionalmente sem operação.
    }
};
