<?php

namespace App\Console\Commands;

use App\Models\Agents;
use Illuminate\Console\Command;

class CheckLids extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-lids';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar captura e armazenamento de @lids nos agentes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando captura de @lids nos agentes...');

        // Buscar agentes que têm assistant_id ou instance_id
        $agents = Agents::whereNotNull('assistant_id')
                       ->orWhereNotNull('instance_id')
                       ->get();

        if ($agents->isEmpty()) {
            $this->warn('⚠️ Nenhum agente encontrado com @lids configurados.');
            $this->info('Isso é normal se nenhuma instância foi conectada recentemente.');
            return;
        }

        $this->info("📊 Encontrados {$agents->count()} agentes com @lids:");

        foreach ($agents as $agent) {
            $this->line("🤖 <comment>{$agent->name}</comment> (UUID: {$agent->uuid})");
            $this->line("   📞 Phone: {$agent->phone}");
            $this->line("   👤 Assistant ID (@lid do usuário): " . ($agent->assistant_id ?? '<error>NÃO DEFINIDO</error>'));
            $this->line("   📱 Instance ID (@lid da instância): " . ($agent->instance_id ?? '<error>NÃO DEFINIDO</error>'));
            $this->line("   🔒 Modo Pessoal: " . ($agent->is_personal ? 'SIM' : 'NÃO'));
            $this->line("   📊 Status: {$agent->status}");
            $this->line("   ─────────────────────────────────");
        }

        // Verificar se algum agente tem ambos os @lids
        $completeAgents = $agents->filter(function($agent) {
            return !empty($agent->assistant_id) && !empty($agent->instance_id);
        });

        if ($completeAgents->count() > 0) {
            $this->info("\n✅ {$completeAgents->count()} agentes têm ambos os @lids configurados!");
            $this->info("🎉 Sistema está capturando os @lids corretamente!");
            $this->line("   • <info>assistant_id</info>: @lid para interação com usuários");
            $this->line("   • <info>instance_id</info>: @lid da própria instância");
        } else {
            $this->warn("\n⚠️ Ainda não há agentes com ambos os @lids configurados.");
            $this->info("Isso é normal se nenhuma instância foi conectada recentemente.");
        }

        $this->info("\n🏁 Verificação concluída.");
    }
}
