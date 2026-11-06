<?php

namespace App\Http\Livewire\Agents;

use Illuminate\Support\Str;
use App\Models\Agents;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Helpers\AgentTrainingBuilder;
use App\Models\User;
use App\Jobs\GenerateAgentTrainingPrompts;

class CreateAgentController extends Component
{
    use WithFileUploads;
    public $agentUuid;
    public $user;
    public $name;
    public $avatar;
    public $agentType = 'assistente';
    public $personality;
    public $autonomy;
    public $gender;
    public $training = '';
    public $faq = '';
    public $negativeTags = '';
    public $instructions = '';
    public $conversationEndings = '';
    public $closing = '';
    public $autoFollowup = false;
    public $followupDelay = 5;
    public $leadInterval = 6;
    public $audio = false;
    public $midia = false;
    public $isPersonal = false;
    public $saudacao = false;
    public $nameUser = true;
    public $maxCharacters = 500;
    public $contextWindowSize = 15;
    public $forbiddenTerms = '';
    public $generalTraining = '';
    public $activeTab = 'wizard-info'; // Valor padrão para a primeira aba
    public $isFinish = false;
    public $isProcessing = false; // Flag para controlar loading durante criação/geracao de prompts

    public function mount()
    {
        $type = request()->get('type');
        if ($type) {
            $this->agentType = $type;
        }
        view()->share('title', 'Criar Agente');
        $this->user = User::find(Auth::id());

        // Recupera rascunho salvo (via parâmetro explicito ou rascunho mais recente do usuário)
        $draftId = request()->get('draft');
        $agent = null;

        if ($draftId) {
            $agent = Agents::where([
                'uuid' => $draftId,
                'user_id' => $this->user->uuid,
                'status' => 'draft'
            ])->first();
        }

        if (!$agent) {
            $agent = Agents::where([
                'user_id' => $this->user->uuid,
                'status' => 'draft'
            ])->orderByDesc('updated_at')->first();
        }

        if ($agent) {
            $this->agentUuid = $agent->uuid;
            $this->name = $agent->name;
            $this->avatar = $agent->avatar;
            $this->agentType = $agent->agent_type ?? 'assistente';
            $this->personality = $agent->personality;
            $this->autonomy = $agent->autonomy;
            $this->gender = $agent->gender;
            $this->training = $agent->training;
            $this->instructions = $agent->instructions;
            $this->faq = $agent->faq;
            $this->negativeTags = $agent->negative_tags;
            $this->conversationEndings = $agent->conversation_endings;
            $this->closing = $agent->closing ?? '';
            $this->autoFollowup = (bool) ($agent->auto_followup ?? false);
            $this->followupDelay = $agent->followup_delay ?? 5;
            $this->leadInterval = $agent->lead_interval ?? 6;
            $this->audio = (bool) ($agent->audio ?? false);
            $this->midia = (bool) ($agent->midia ?? false);
            $this->isPersonal = (bool) ($agent->is_personal ?? false);
            $this->saudacao = (bool) ($agent->saudacao ?? false);
            $this->nameUser = (bool) ($agent->nameUser ?? false);
            $this->maxCharacters = $agent->max_characters ?? 500;
            $this->contextWindowSize = $agent->context_window_size ?? 15;
            $this->forbiddenTerms = is_array($agent->forbidden_terms)
                ? implode(', ', $agent->forbidden_terms)
                : ($agent->forbidden_terms ?? '');
            $this->generalTraining = $agent->general_training ?? '';
        }

        // Verificar se o usuário tem plano ativo
        $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;

        // Contar agentes criados (excluindo rascunhos e o agente atual se estiver editando)
        $existingAgentsCount = Agents::where('user_id', $this->user->uuid)
            ->where('status', '!=', 'draft')
            ->when($this->agentUuid, function ($query) {
                // Se estiver editando um rascunho, não contar ele mesmo
                $query->where('uuid', '!=', $this->agentUuid);
            })
            ->count();

        // Se não tiver plano ativo e já tiver 1 agente criado, bloquear
        if (!$hasActivePlan && $existingAgentsCount >= 1 && !$this->agentUuid) {
            return redirect()->route('agentes')->with('error', 'Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para criar mais agentes.');
        }

        // Verificar limite de agentes do plano apenas se não estiver retomando um rascunho existente
        if (!$this->agentUuid && $hasActivePlan && $this->user->plan) {
            $agentLimit = $this->user->plan->agentes ?? 0;
            if ($existingAgentsCount >= $agentLimit) {
                return redirect()->route('agentes')->with('error', 'Quantidade máxima de criação de agentes atingida.');
            }
        }

        $this->negativeTags = "política, religião, drogas, racismo, sexismo, homofobia, assuntos pessoais";
    }

    public function saveManualDraft()
    {
        try {
            $this->saveDraft();
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Rascunho salvo com sucesso!'
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Erro ao salvar rascunho: ' . $e->getMessage()
            ]);
        }
    }

    public function render()
    {
        return view('livewire.agents.create');
    }

    public function setActiveTab($tabId)
    {
        // Salvar o progresso ao mudar de aba apenas se tiver dados mínimos
        if ($tabId !== 'isFinish') {
            $this->activeTab = $tabId;
            // Só salva se tiver pelo menos nome e telefone preenchidos
            if ($this->name) {
                try {
                    $this->saveDraft();
                } catch (\Exception $e) {
                    // Se der erro no auto-save, apenas mostra uma mensagem discreta
                    $this->dispatch('notify', [
                        'type' => 'warning',
                        'message' => 'Preencha todos os campos obrigatórios para salvar automaticamente.'
                    ]);
                }
            }
        } else {
            // NÃO alterar a tab até que a validação passe
            $this->isFinish = true;
            $this->createAgent();
        }
    }

    public function createAgent()
    {
        // Evitar múltiplas execuções simultâneas
        if ($this->isProcessing) {
            return;
        }

        $this->isProcessing = true;

        try {
            // Verificar se o usuário tem plano ativo
            $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;

            // Contar agentes criados (excluindo rascunhos e o agente atual se estiver editando)
            $existingAgentsCount = Agents::where('user_id', $this->user->uuid)
                ->where('status', '!=', 'draft')
                ->when($this->agentUuid, function ($query) {
                    // Se estiver editando um rascunho, não contar ele mesmo
                    $query->where('uuid', '!=', $this->agentUuid);
                })
                ->count();

            // Se não tiver plano ativo e já tiver 1 agente criado, bloquear
            if (!$hasActivePlan && $existingAgentsCount >= 1) {
                session()->flash('error', 'Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para criar mais agentes.');
                $this->isProcessing = false;
                return redirect()->route('agentes');
            }

            // Verificar limite de agentes do plano se tiver plano ativo
            if ($hasActivePlan && $this->user->plan) {
                $agentLimit = $this->user->plan->agentes ?? 0;
                if ($existingAgentsCount >= $agentLimit) {
                    session()->flash('error', 'Quantidade máxima de criação de agentes atingida.');
                    $this->isProcessing = false;
                    return redirect()->route('agentes');
                }
            }

            // Definir valores padrão se não preenchidos ou inválidos
            if (!in_array($this->autonomy, ['baixa', 'moderada', 'alta'])) $this->autonomy = 'moderada';
            if (!in_array($this->gender, ['masculino', 'feminino', 'neutro'])) $this->gender = 'neutro';
            if (!in_array($this->personality, ['formal', 'casual', 'friendly', 'professional'])) $this->personality = 'friendly';

            // NOTA: Geração de prompts removida para evitar timeout do Cloudflare (limite de 100 segundos)
            // Os prompts devem ser gerados manualmente ou através de uma ação separada que não cause timeout

            // Validação completa antes de finalizar
            $this->validate();

            // Salvar o rascunho com todos os dados atuais
            $agent = $this->saveDraft();

            // Disparar Job para gerar prompts em background se generalTraining estiver preenchido
            if (!empty($this->generalTraining)) {
                // Verificar créditos antes de disparar o Job
                if (AgentTrainingBuilder::hasEnoughCredits($this->user, 8)) {
                    GenerateAgentTrainingPrompts::dispatch(
                        $agent->uuid,
                        $this->generalTraining,
                        $this->agentType,
                        $this->user->uuid
                    );

                    $this->dispatch('notify', [
                        'type' => 'info',
                        'message' => 'Geração de treinamento iniciada em background. Os prompts serão atualizados automaticamente quando concluídos.'
                    ]);
                }
            }

            // Atualizar status para ativo
            if ($this->isFinish) {
                $agent->status = 'enabled';
                $agent->save();

                $agent->refresh();

                $this->isProcessing = false;

                return redirect()->route('agentes', $agent->uuid)->with('message', 'Agente criado com sucesso!');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->isProcessing = false;
            $this->isFinish = false;
            // Não alterar a tab - mantém na tab atual (training-wizard)
            // Os erros de validação serão exibidos automaticamente pelo Livewire
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Por favor, corrija os erros no formulário antes de finalizar.'
            ]);
        } catch (\Exception $e) {
            $this->isProcessing = false;
            $this->isFinish = false;
            // Não alterar a tab - mantém na tab atual (training-wizard)
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Erro ao criar agente: ' . $e->getMessage()
            ]);
        }
    }

    public function saveDraft()
    {
        // Validação mais flexível para rascunho - apenas campos obrigatórios básicos
        $this->validate([
            'name' => 'required|string|max:255',
            'agentType' => 'required|string|in:assistente,vendas,suporte',
            'personality' => 'nullable|string|in:formal,casual,friendly,professional',
            'autonomy' => 'nullable|string|in:baixa,moderada,alta',
            'gender' => 'nullable|string|in:masculino,feminino,neutro',
            'training' => 'nullable|string',
            'faq' => 'nullable|string',
            'negativeTags' => 'nullable|string',
            'instructions' => 'nullable|string',
            'conversationEndings' => 'nullable|string',
            'closing' => 'nullable|string',
            'autoFollowup' => 'nullable|boolean',
            'followupDelay' => 'nullable|integer|between:5,60',
            'leadInterval' => 'required|integer|min:1|max:15',
            'audio' => 'nullable|boolean',
            'midia' => 'nullable|boolean',
            'isPersonal' => 'nullable|boolean',
            'saudacao' => 'nullable|boolean',
            'nameUser' => 'nullable|boolean',
            'maxCharacters' => 'nullable|integer|min:100|max:4000',
            'contextWindowSize' => 'nullable|integer|min:5|max:50',
            'forbiddenTerms' => 'nullable|string',
            'generalTraining' => 'required|string',
        ]);

        $agentUuid = $this->agentUuid ?? Str::limit(Str::uuid(), 8, '');

        // Processar avatar se existir
        if ($this->avatar && !is_string($this->avatar)) {
            $this->validate(['avatar' => 'nullable|image|max:2048']);
            $filename = 'avatar_' . $agentUuid . '.' . $this->avatar->extension();
            $avatarPath = $this->avatar->storeAs('/agents', $filename, 'assets');
        } else {
            $avatarPath = $this->avatar;
        }

        // Definir valores padrão para campos obrigatórios no banco
        $autonomy = in_array($this->autonomy, ['baixa', 'moderada', 'alta']) ? $this->autonomy : 'moderada';
        $gender = in_array($this->gender, ['masculino', 'feminino', 'neutro']) ? $this->gender : 'neutro';
        $personality = in_array($this->personality, ['formal', 'casual', 'friendly', 'professional']) ? $this->personality : 'friendly';

        // ✅ Verificar se é o primeiro agente do usuário para aplicar is_trial
        $existingAgentsCount = Agents::where('user_id', $this->user->uuid)
            ->where('status', '!=', 'draft')
            ->when($this->agentUuid, function ($query) {
                $query->where('uuid', '!=', $this->agentUuid);
            })
            ->count();

        // Criar ou atualizar o agente como draft
        $agent = Agents::updateOrCreate(
            ['uuid' => $agentUuid],
            [
                'user_id' => $this->user->uuid,
                'name' => $this->name,
                'channel' => 'whatsapp',
                'agent_type' => $this->agentType,
                'personality' => $personality,
                'autonomy' => $autonomy,
                'gender' => $gender,
                'training' => $this->training ?: '',
                'faq' => $this->faq ?: '',
                'negative_tags' => $this->negativeTags ?: '',
                'instructions' => $this->instructions ?: '',
                'conversation_endings' => $this->conversationEndings ?: '',
                'closing' => $this->closing ?: '',
                'auto_followup' => (bool) $this->autoFollowup,
                'followup_delay' => $this->followupDelay ?: 5,
                'lead_interval' => $this->leadInterval ?: 6,
                'audio' => (bool) $this->audio,
                'midia' => (bool) $this->midia,
                'is_personal' => (bool) $this->isPersonal,
                'saudacao' => (bool) $this->saudacao,
                'nameUser' => (bool) $this->nameUser,
                'max_characters' => $this->maxCharacters ?? 500,
                'context_window_size' => $this->contextWindowSize ?? 15,
                'forbidden_terms' => $this->forbiddenTerms ?: null,
                'general_training' => $this->generalTraining ?: null,
                'avatar' => $avatarPath,
                'is_trial' => $existingAgentsCount == 0 ? 1 : 0, // ✅ Primeiro agente = trial
                'status' => 'draft',
            ]
        );

        $agent->refresh();

        $this->agentUuid = $agent->uuid;

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Progresso salvo com sucesso!'
        ]);

        return $agent;
    }

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'agentType' => 'required|string|in:assistente,vendas,suporte',
            'personality' => 'nullable|string|in:formal,casual,friendly,professional',
            'autonomy' => 'nullable|string|in:baixa,moderada,alta',
            'gender' => 'nullable|string|in:masculino,feminino,neutro',
            'training' => 'nullable|string',
            'faq' => 'nullable|string',
            'negativeTags' => 'nullable|string',
            'instructions' => 'nullable|string',
            'conversationEndings' => 'nullable|string',
            'closing' => 'nullable|string',
            'autoFollowup' => 'nullable|boolean',
            'followupDelay' => 'nullable|integer|between:5,60',
            'leadInterval' => 'required|integer|min:1|max:15',
            'audio' => 'nullable|boolean',
            'midia' => 'nullable|boolean',
            'isPersonal' => 'nullable|boolean',
            'saudacao' => 'nullable|boolean',
            'nameUser' => 'nullable|boolean',
            'generalTraining' => 'required|string',
        ];
    }

    protected function messages()
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.string' => 'O nome deve ser um texto.',
            'name.max' => 'O nome deve ter no máximo 255 caracteres.',
            'agentType.required' => 'O tipo do agente é obrigatório.',
            'agentType.in' => 'O tipo do agente selecionado é inválido.',
            'personality.in' => 'A personalidade selecionada é inválida.',
            'autonomy.in' => 'A autonomia selecionada é inválida.',
            'gender.in' => 'O gênero selecionado é inválido.',
            'training.string' => 'O campo de treinamento deve ser um texto.',
            'faq.string' => 'O campo FAQ deve ser um texto.',
            'negative_tags.string' => 'As tags restritivas devem ser um texto.',
            'instructions.string' => 'As instruções devem ser um texto.',
            'conversationEndings.string' => 'As frases de encerramento devem ser um texto.',
            'autoFollowup.boolean' => 'O campo de follow-up automático deve ser verdadeiro ou falso.',
            'followupDelay.integer' => 'O delay do follow-up deve ser um número inteiro.',
            'followupDelay.between' => 'O delay do follow-up deve estar entre 5 e 60 minutos.',
            'leadInterval.required' => 'Informe o intervalo de mensagens para qualificação.',
            'leadInterval.integer' => 'O intervalo de mensagens deve ser um número inteiro.',
            'leadInterval.min' => 'O intervalo de mensagens deve ser de pelo menos 1.',
            'leadInterval.max' => 'O intervalo de mensagens deve ser no máximo 15.',
            'avatar.image' => 'O avatar deve ser uma imagem válida.',
            'avatar.max' => 'A imagem não pode ultrapassar 2MB.',
            'audio.boolean' => 'O campo de áudio deve ser verdadeiro ou falso.',
            'midia.boolean' => 'O campo de mídia deve ser verdadeiro ou falso.',
            'isPersonal.boolean' => 'O campo de assistente pessoal deve ser verdadeiro ou falso.',
            'saudacao.boolean' => 'O campo de saudação deve ser verdadeiro ou falso.',
            'nameUser.boolean' => 'O campo de nome do usuário deve ser verdadeiro ou falso.',
            'maxCharacters.integer' => 'O limite de caracteres deve ser um número inteiro.',
            'maxCharacters.min' => 'O limite de caracteres deve ser no mínimo 100.',
            'maxCharacters.max' => 'O limite de caracteres deve ser no máximo 4000.',
            'contextWindowSize.integer' => 'O tamanho da janela de contexto deve ser um número inteiro.',
            'contextWindowSize.min' => 'O tamanho da janela de contexto deve ser no mínimo 5.',
            'contextWindowSize.max' => 'O tamanho da janela de contexto deve ser no máximo 50.',
            'forbiddenTerms.string' => 'Os termos proibidos devem ser um texto.',
            'generalTraining.required' => 'A descrição do nicho e treinamento geral é obrigatória.',
            'generalTraining.string' => 'A descrição do nicho deve ser um texto.',
        ];
    }
}
