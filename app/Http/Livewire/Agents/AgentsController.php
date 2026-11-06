<?php

namespace App\Http\Livewire\Agents;

use App\Models\Agents;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class AgentsController extends Component
{
    public $user;

    public function mount()
    {
        view()->share('title', 'Agentes');
        $this->user = Auth::User();
    }

    public function render()
    {
        // Buscar rascunhos do usuário para mostrar na interface
        $drafts = Agents::where(['user_id' => $this->user->uuid, 'status' => 'draft'])
            ->orderBy('updated_at', 'desc')
            ->get();

        // Buscar todos os agentes do usuário (incluindo desativados)
        $agents = Agents::where('user_id', $this->user->uuid)
            ->where('status', '!=', 'draft') // Excluir rascunhos que já são buscados separadamente
            ->orderByRaw("CASE WHEN status = 'enabled' THEN 1 ELSE 2 END") // Ativos primeiro
            ->orderBy('updated_at', 'desc')
            ->get();

        return view('livewire.agents.index', compact('drafts', 'agents'));
    }

    public function copyAgent($id)
    {
        $agente = Agents::where('uuid', $id)->where('user_id', $this->user->uuid)->firstOrFail();
        $agentUuid = Str::limit(Str::uuid(), 8, '');

        // Verificar se o usuário tem plano ativo
        $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;

        // Contar agentes criados (excluindo rascunhos)
        $existingAgentsCount = Agents::where('user_id', $this->user->uuid)
            ->where('status', '!=', 'draft')
            ->count();

        // Se não tiver plano ativo e já tiver 1 agente criado, bloquear
        if (!$hasActivePlan && $existingAgentsCount >= 1) {
            session()->flash('error', 'Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para criar mais agentes.');
            return;
        }

        // Verificar limite de agentes do plano se tiver plano ativo
        if ($hasActivePlan && $this->user->plan) {
            $agentLimit = $this->user->plan->agentes ?? 0;
            if ($existingAgentsCount >= $agentLimit) {
                session()->flash('error', 'Quantidade máxima de criação de agentes atingida.');
                return;
            }
        }

        // ✅ Verificar se é o primeiro agente do usuário para aplicar is_trial
        $isFirstAgent = $existingAgentsCount == 0;

        $newAgent = Agents::create([
            'uuid' => $agentUuid,
            'user_id' => $this->user->uuid,
            'name' => $agente->name . ' (cópia)',
            'phone' => null,
            'phone_id' => null,
            'channel' => $agente->channel,
            'training' => $agente->training,
            'expertise' => $agente->expertise,
            'autonomy' => $agente->autonomy,
            'personality' => $agente->personality,
            'behavior' => $agente->behavior ?? 'disabled',
            'resp_time' => $agente->resp_time,
            'faq' => $agente->faq,
            'audio' => $agente->audio,
            'midia' => $agente->midia,
            'is_personal' => $agente->is_personal,
            'saudacao' => $agente->saudacao,
            'nameUser' => $agente->nameUser,
            'personal_skills' => $agente->personal_skills,
            'gender' => $agente->gender,
            'avatar' => null, // ou mantenha se quiser
            'negative_tags' => $agente->negative_tags,
            'closing' => $agente->closing,
            'is_trial' => $isFirstAgent ? 1 : 0, // ✅ Primeiro agente = trial
            'status' => 'disabled', // novo agente começa desativado
        ]);

        return redirect()->route('agents.edit', $newAgent->uuid)
            ->with('message', 'Agente duplicado com sucesso. Valide e salve as informações.');
    }

    /**
     * Continuar editando um rascunho
     */
    public function continueDraft($draftId)
    {
        $draft = Agents::where(['uuid' => $draftId, 'user_id' => $this->user->uuid, 'status' => 'draft'])->first();

        if (!$draft) {
            session()->flash('error', 'Rascunho não encontrado.');
            return;
        }

        return redirect()->route('agents.create', ['draft' => $draftId])
            ->with('message', 'Continuando edição do rascunho: ' . $draft->name);
    }

    /**
     * Excluir um rascunho
     */
    public function deleteDraft($draftId)
    {
        $draft = Agents::where(['uuid' => $draftId, 'user_id' => $this->user->uuid, 'status' => 'draft'])->first();

        if (!$draft) {
            session()->flash('error', 'Rascunho não encontrado.');
            return;
        }

        // Remover avatar se existir
        if ($draft->avatar && file_exists(public_path('assets/' . $draft->avatar))) {
            unlink(public_path('assets/' . $draft->avatar));
        }

        $draft->delete();

        session()->flash('message', 'Rascunho "' . $draft->name . '" excluído com sucesso.');
    }

    /**
     * Criar um novo agente (limpo, sem rascunho)
     */
    public function createNewAgent()
    {
        // Verificar se o usuário tem plano ativo
        $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;

        // Contar agentes criados (excluindo rascunhos)
        $existingAgentsCount = Agents::where('user_id', $this->user->uuid)
            ->where('status', '!=', 'draft')
            ->count();

        // Se não tiver plano ativo e já tiver 1 agente criado, bloquear
        if (!$hasActivePlan && $existingAgentsCount >= 1) {
            session()->flash('error', 'Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para criar mais agentes.');
            return;
        }

        // Verificar limite de agentes do plano se tiver plano ativo
        if ($hasActivePlan && $this->user->plan) {
            $agentLimit = $this->user->plan->agentes ?? 0;
            if ($existingAgentsCount >= $agentLimit) {
                session()->flash('error', 'Quantidade máxima de criação de agentes atingida.');
                return;
            }
        }

        return redirect()->route('agents.create')
            ->with('message', 'Iniciando criação de novo agente.');
    }

    /**
     * Mostrar confirmação para toggle de status
     */
    public function showToggleConfirm($agentId, $agentName, $currentStatus)
    {
        $action = $currentStatus === 'enabled' ? 'desativar' : 'ativar';

        $this->dispatch('showToggleAgentConfirm', [
            'id' => $agentId,
            'name' => $agentName,
            'currentStatus' => $currentStatus,
            'action' => $action
        ]);
    }

    /**
     * Alternar status do agente (ativar/desativar)
     */
    #[On('toggleAgentStatus')]
    public function toggleAgentStatus($agentId)
    {
        $agent = Agents::where('uuid', $agentId)->where('user_id', $this->user->uuid)->first();

        if (!$agent) {
            session()->flash('error', 'Agente não encontrado.');
            return;
        }

        // Toggle status
        $newStatus = $agent->status === 'enabled' ? 'disabled' : 'enabled';
        $agent->update(['status' => $newStatus]);

        $statusText = $newStatus === 'enabled' ? 'ativado' : 'desativado';

        $this->dispatch('agentStatusToggled', "Agente '{$agent->name}' {$statusText} com sucesso!");

        // Atualizar a lista de agentes
        $this->render();
    }

    /**
     * Verifica se há jobs pendentes de geração de prompts para os agentes do usuário
     * Usado para polling no frontend
     */
    public function checkAgentsTrainingStatus()
    {
        try {
            // Verificar se há jobs pendentes na tabela de jobs relacionados a GenerateAgentTrainingPrompts
            $pendingJobs = DB::table('jobs')
                ->where('queue', 'default')
                ->where('payload', 'like', '%GenerateAgentTrainingPrompts%')
                ->count();

            // Verificar se há agentes do usuário que foram atualizados recentemente (últimos 3 minutos)
            // Isso pode indicar que um job foi processado
            $recentlyUpdatedAgents = Agents::where('user_id', $this->user->uuid)
                ->where('status', '!=', 'draft')
                ->where('updated_at', '>=', now()->subMinutes(3))
                ->get();

            $recentlyUpdatedCount = $recentlyUpdatedAgents->count();

            // Se não há jobs pendentes mas houve atualização recente, pode ter sido concluído
            $shouldRefresh = $pendingJobs == 0 && $recentlyUpdatedCount > 0;

            return [
                'has_pending_jobs' => $pendingJobs > 0,
                'recently_updated_count' => $recentlyUpdatedCount,
                'should_refresh' => $shouldRefresh
            ];
        } catch (\Exception $e) {
            // Se houver erro (ex: tabela jobs não existe), retornar valores padrão
            return [
                'has_pending_jobs' => false,
                'recently_updated_count' => 0,
                'should_refresh' => false
            ];
        }
    }
}
