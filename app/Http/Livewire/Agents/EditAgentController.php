<?php

namespace App\Http\Livewire\Agents;

use App\Models\Agents;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Helpers\AgentTrainingBuilder;
use App\Jobs\GenerateAgentTrainingPrompts;

class EditAgentController extends Component
{
    use WithFileUploads;
    public $agent;
    public $agentUuid;
    public $settings;
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
    public $nameUser = false;
    public $maxCharacters = 500;
    public $contextWindowSize = 15;
    public $forbiddenTerms = '';
    public $generalTraining = '';
    public $existingAvatar;
    public $activeTab = 'wizard-info';
    public $isFinish = false;
    public $isProcessing = false; // Flag para controlar loading durante atualização/geração de prompts
    public $originalInstanceId;
    public $originalInstanceToken;

    public function mount($agentId)
    {
        view()->share("title", "Gerenciar Agente");
        $this->settings = Settings::first();
        $this->user = User::find(Auth::id());

        $agent = Agents::where(["uuid" => $agentId, "user_id" => Auth::user()->uuid])->first();
        if (!$agent) {
            return redirect()->to("agentes")->with("error", "Agente não encontrado, verifique e tente novamente!");
        }

        $this->agent = $agent;
        $this->agentUuid = $agent->uuid;
        $this->name = $agent->name;
        $this->avatar = $agent->avatar;
        $this->existingAvatar = $agent->avatar;
        $this->agentType = $agent->agent_type ?? 'assistente';
        $this->personality = $agent->personality;
        $this->autonomy = $agent->autonomy;
        $this->gender = $agent->gender;
        $this->training = $agent->training;
        $this->faq = $agent->faq;
        $this->negativeTags = $agent->negative_tags;
        $this->instructions = $agent->instructions;
        $this->conversationEndings = $agent->conversation_endings;
        $this->closing = $agent->closing ?? '';
        $this->autoFollowup = $agent->auto_followup ?? false;
        $this->followupDelay = $agent->followup_delay ?? 5;
        $this->leadInterval = $agent->lead_interval ?? 6;
        $this->audio = $agent->audio;
        $this->midia = $agent->midia;
        $this->isPersonal = $agent->is_personal;
        $this->saudacao = $agent->saudacao;
        $this->nameUser = $agent->nameUser;
        $this->maxCharacters = $agent->max_characters ?? 500;
        $this->contextWindowSize = $agent->context_window_size ?? 15;
        $this->forbiddenTerms = is_array($agent->forbidden_terms)
            ? implode(', ', $agent->forbidden_terms)
            : ($agent->forbidden_terms ?? '');
        $this->generalTraining = $agent->general_training ?? '';
    }

    public function render()
    {
        return view("livewire.agents.edit");
    }

    public function setActiveTab($tabId)
    {
        if ($tabId == 'isFinish') {
            // NÃO alterar a tab até que a validação passe
            $this->isFinish = true;
            $this->updateAgent();
            return;
        }

        $this->activeTab = $tabId;
    }

    public function updateAgent()
    {
        // Evitar múltiplas execuções simultâneas
        if ($this->isProcessing) {
            return;
        }

        $this->isProcessing = true;

        try {
            // Verificar se o generalTraining mudou em relação ao que está salvo no banco
            $originalGeneralTraining = $this->agent->general_training ?? '';
            $generalTrainingChanged = trim($this->generalTraining) !== trim($originalGeneralTraining);

            // Validação completa antes de finalizar
            $this->validate();

            // Atualiza o agente com os dados fornecidos
            $agent = $this->applyAgentUpdates();

            // Disparar Job para gerar prompts em background se generalTraining mudou e estiver preenchido
            if ($generalTrainingChanged && !empty($this->generalTraining)) {
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
                } else {
                    $totalCredits = ($this->user->credits ?? 0) + ($this->user->combo ?? 0);
                    $this->dispatch('notify', [
                        'type' => 'error',
                        'message' => "Você não possui créditos suficientes para gerar o treinamento. Você possui {$totalCredits} crédito(s) e são necessários pelo menos 8 créditos."
                    ]);
                }
            }

            // Atualizar status para ativo
            if ($this->isFinish) {
                $agent->status = 'enabled';
                $agent->save();
                $agent->refresh();

                $this->isProcessing = false;

                return redirect()->route('agentes', $agent->uuid)->with('message', 'Agente atualizado com sucesso!');
            }

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Dados atualizados com sucesso!'
            ]);

            $this->isProcessing = false;
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
                'message' => 'Erro ao atualizar agente: ' . $e->getMessage()
            ]);
        }
    }

    private function applyAgentUpdates(): Agents
    {
        $agent = $this->agent ?? Agents::where('uuid', $this->agentUuid)->where('user_id', Auth::user()->uuid)->firstOrFail();

        $avatarPath = $agent->avatar;

        if ($this->avatar && !is_string($this->avatar)) {
            $this->validate(['avatar' => 'nullable|image|max:2048']);
            $filename = 'avatar_' . $agent->uuid . '.' . $this->avatar->extension();
            $avatarPath = $this->avatar->storeAs('/agents', $filename, 'assets');
        } elseif (is_string($this->avatar)) {
            $avatarPath = $this->avatar;
        }

        $agent->fill([
            "user_id" => Auth::user()->uuid,
            "name" => $this->name,
            "channel" => "whatsapp",
            "agent_type" => $this->agentType,
            "personality" => $this->personality,
            "autonomy" => $this->autonomy,
            "gender" => $this->gender,
            "training" => $this->training,
            "faq" => $this->faq,
            "negative_tags" => $this->negativeTags,
            "instructions" => $this->instructions,
            "conversation_endings" => $this->conversationEndings,
            "closing" => $this->closing ?: '',
            "auto_followup" => (bool) $this->autoFollowup,
            "followup_delay" => $this->followupDelay,
            "lead_interval" => $this->leadInterval ?: 6,
            "audio" => (bool) $this->audio,
            "midia" => (bool) $this->midia,
            "is_personal" => (bool) $this->isPersonal,
            "saudacao" => (bool) $this->saudacao,
            "nameUser" => (bool) $this->nameUser,
            "max_characters" => $this->maxCharacters ?? 500,
            "context_window_size" => $this->contextWindowSize ?? 15,
            "forbidden_terms" => $this->forbiddenTerms ?: null,
            "general_training" => $this->generalTraining ?: null,
        ]);

        if ($avatarPath) {
            $agent->avatar = $avatarPath;
        }

        if ($this->isFinish) {
            $agent->status = 'enabled';
        }

        $agent->save();
        $agent->refresh();

        $this->agent = $agent;
        $this->agentUuid = $agent->uuid;

        return $agent;
    }

    protected function rules()
    {
        return [
            "name" => "required|string|max:255",
            "agentType" => "required|string|in:assistente,vendas,suporte",
            "personality" => "nullable|string|in:formal,casual,friendly,professional",
            "autonomy" => "nullable|string|in:baixa,moderada,alta",
            "gender" => "nullable|string|in:masculino,feminino,neutro",
            "training" => "nullable|string",
            "faq" => "nullable|string",
            "negativeTags" => "nullable|string",
            "instructions" => "nullable|string",
            "conversationEndings" => "nullable|string",
            "closing" => "nullable|string",
            "autoFollowup" => "nullable|boolean",
            "followupDelay" => "nullable|integer|between:5,60",
            "leadInterval" => "required|integer|min:1|max:15",
            "audio" => "nullable|boolean",
            "midia" => "nullable|boolean",
            "isPersonal" => "nullable|boolean",
            "saudacao" => "nullable|boolean",
            "nameUser" => "nullable|boolean",
            "maxCharacters" => "nullable|integer|min:100|max:4000",
            "contextWindowSize" => "nullable|integer|min:5|max:50",
            "forbiddenTerms" => "nullable|string",
            "generalTraining" => "required|string",
        ];
    }

    protected function messages()
    {
        return [
            "name.required" => "O nome é obrigatório.",
            "name.string" => "O nome deve ser um texto.",
            "name.max" => "O nome deve ter no máximo 255 caracteres.",
            "agentType.required" => "O tipo do agente é obrigatório.",
            "agentType.in" => "O tipo do agente selecionado é inválido.",
            "personality.in" => "A personalidade selecionada é inválida.",
            "autonomy.in" => "A autonomia selecionada é inválida.",
            "gender.in" => "O gênero selecionado é inválido.",
            "training.string" => "O campo de treinamento deve ser um texto.",
            "faq.string" => "O campo FAQ deve ser um texto.",
            "negative_tags.string" => "As tags restritivas devem ser um texto.",
            "instructions.string" => "As instruções devem ser um texto.",
            "conversationEndings.string" => "As frases de encerramento devem ser um texto.",
            "autoFollowup.boolean" => "O campo de follow-up automático deve ser verdadeiro ou falso.",
            "followupDelay.integer" => "O delay do follow-up deve ser um número inteiro.",
            "followupDelay.between" => "O delay do follow-up deve estar entre 5 e 60 minutos.",
            "leadInterval.required" => "Informe o intervalo de mensagens para qualificação.",
            "leadInterval.integer" => "O intervalo de mensagens deve ser um número inteiro.",
            "leadInterval.min" => "O intervalo de mensagens deve ser de pelo menos 1.",
            "leadInterval.max" => "O intervalo de mensagens deve ser no máximo 15.",
            "avatar.image" => "O avatar deve ser uma imagem válida.",
            "avatar.max" => "A imagem não pode ultrapassar 2MB.",
            "audio.boolean" => "O campo de áudio deve ser verdadeiro ou falso.",
            "midia.boolean" => "O campo de mídia deve ser verdadeiro ou falso.",
            "isPersonal.boolean" => "O campo de assistente pessoal deve ser verdadeiro ou falso.",
            "saudacao.boolean" => "O campo de saudação deve ser verdadeiro ou falso.",
            "nameUser.boolean" => "O campo de nome do usuário deve ser verdadeiro ou falso.",
            "maxCharacters.integer" => "O limite de caracteres deve ser um número inteiro.",
            "maxCharacters.min" => "O limite de caracteres deve ser no mínimo 100.",
            "maxCharacters.max" => "O limite de caracteres deve ser no máximo 4000.",
            "contextWindowSize.integer" => "O tamanho da janela de contexto deve ser um número inteiro.",
            "contextWindowSize.min" => "O tamanho da janela de contexto deve ser no mínimo 5.",
            "contextWindowSize.max" => "O tamanho da janela de contexto deve ser no máximo 50.",
            "forbiddenTerms.string" => "Os termos proibidos devem ser um texto.",
            "generalTraining.required" => "A descrição do nicho e treinamento geral é obrigatória.",
            "generalTraining.string" => "A descrição do nicho deve ser um texto.",
        ];
    }
}
