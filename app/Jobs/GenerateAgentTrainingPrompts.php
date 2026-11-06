<?php

namespace App\Jobs;

use App\Helpers\AgentTrainingBuilder;
use App\Models\Agents;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAgentTrainingPrompts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600; // 10 minutos (4 chamadas à OpenAI + margem de segurança)

    protected $agentUuid;
    protected $generalTraining;
    protected $agentType;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $agentUuid, string $generalTraining, string $agentType, string $userId)
    {
        $this->agentUuid = $agentUuid;
        $this->generalTraining = $generalTraining;
        $this->agentType = $agentType;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $agent = Agents::where('uuid', $this->agentUuid)->first();
            if (!$agent) {
                Log::error('GenerateAgentTrainingPrompts: Agente não encontrado', [
                    'agent_uuid' => $this->agentUuid
                ]);
                return;
            }

            $user = User::where('uuid', $this->userId)->first();
            if (!$user) {
                Log::error('GenerateAgentTrainingPrompts: Usuário não encontrado', [
                    'user_uuid' => $this->userId
                ]);
                return;
            }


            // Verificar créditos antes de gerar
            if (!AgentTrainingBuilder::hasEnoughCredits($user, 8)) {
                Log::warning('GenerateAgentTrainingPrompts: Créditos insuficientes', [
                    'user_uuid' => $this->userId,
                    'agent_uuid' => $this->agentUuid
                ]);
                return;
            }

            // Extrair configurações do agente para passar ao gerador
            $agentConfig = [
                'gender' => $agent->gender,
                'personality' => $agent->personality,
                'autonomy' => $agent->autonomy,
                'max_characters' => $agent->max_characters ?? 500,
                'negative_tags' => $agent->negative_tags,
            ];

            // ✅ Gerar prompts com MODO OTIMIZADO (condensação inteligente via IA)
            $prompts = AgentTrainingBuilder::generateTrainingPrompts(
                $this->generalTraining,
                $this->agentType,
                $user,
                $agentConfig,
                true // ✅ Modo otimizado ativado
            );

            // Atualizar agente com os prompts gerados
            $updateData = [
                'training' => $prompts['training'],
                'instructions' => $prompts['instructions'],
                'faq' => $prompts['faq'],
                'closing' => $prompts['closing'],
            ];

            // ✅ Se training_optimized foi gerado, salvar também
            if (isset($prompts['training_optimized'])) {
                $updateData['training_optimized'] = $prompts['training_optimized'];

                Log::info('Training otimizado salvo no agente', [
                    'agent_uuid' => $this->agentUuid,
                    'optimized_length' => strlen($prompts['training_optimized'])
                ]);
            }

            $agent->update($updateData);

            Log::info('GenerateAgentTrainingPrompts: Prompts gerados com sucesso', [
                'agent_uuid' => $this->agentUuid,
                'user_uuid' => $this->userId,
                'has_optimized' => isset($prompts['training_optimized'])
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateAgentTrainingPrompts: Erro ao gerar prompts', [
                'agent_uuid' => $this->agentUuid,
                'user_uuid' => $this->userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
