<?php

namespace App\Http\Livewire\PersonalChat;

use App\Helpers\Uuid;
use App\Http\Controllers\Knowledge\Context\ContextController;
use App\Models\Agents;
use App\Models\Chats;
use App\Models\Messages;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class PersonalChatController extends Component
{
    public $user;
    public $userId;
    public $chats = [];
    public $selectedChat = null;
    public $messages = [];
    public $agents = [];
    public $models = [];
    public $showCreateModal = false;
    public $selectedAgentId = null;
    public $selectedModel = null;
    public $userMessage = '';
    public $isSending = false;
    public $agent = null;
    public $chatMode = 'agent'; // 'agent' ou 'model'

    // Indicadores de digitação para experiência fluida
    public $isAgentTyping = false;
    public $typingMessage = '';
    public $typingProgress = 0;

    // Flag para evitar loop infinito
    private $isProcessingMessage = false;

    // Flag para evitar múltiplas respostas
    private $isProcessingResponse = false;
    private $lastProcessedMessage = null;
    private $lastResponseTimestamp = null;

    protected $listeners = [
        'messageReceived',
        'sendAudioMessage'
        // Removido 'processAgentResponseNow' do listener para evitar duplicação
        // O método é chamado diretamente via @this.call() do JavaScript
    ];

    public function mount()
    {
        $this->user = Auth::user();
        $this->userId = $this->user->uuid;
        view()->share('title', 'Chat Pessoal');

        // Carrega chats do usuário
        $this->loadChats();

        // Carrega agentes disponíveis do usuário
        $this->agents = Agents::where('user_id', $this->userId)
            ->where('status', 'enabled')
            ->orderBy('name', 'asc')
            ->get();

        // Carrega modelos disponíveis
        $this->loadAvailableModels();

        // Seleciona primeiro chat se existir
        $this->selectedChat = collect($this->chats)->first();
        if ($this->selectedChat && $this->selectedChat->agent) {
            $this->loadMessages($this->selectedChat->uuid);
            $this->agent = $this->selectedChat->agent;
        } else {
            $this->selectedChat = null;
            $this->agent = null;
            $this->messages = [];
        }
    }

    public function render()
    {
        return view('livewire.chats.personal');
    }

    /**
     * Carrega lista de chats do usuário
     * Filtra apenas chats onde phone é null (não telefone)
     */
    public function loadChats()
    {
        $this->chats = Chats::where('user_id', $this->userId)
            ->with('agent')
            ->withCount('messages')
            ->whereNull('phone')
            ->orderBy('time', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Carrega modelos disponíveis para seleção
     */
    public function loadAvailableModels()
    {
        $this->models = AiModelCatalog::options();
    }

    /**
     * Seleciona um chat
     */
    public function selectChat($chatId)
    {
        $this->selectedChat = collect($this->chats)->firstWhere('uuid', $chatId);
        if ($this->selectedChat && $this->selectedChat->agent) {
            // Garante que o relacionamento está carregado
            if (!$this->selectedChat->relationLoaded('agent')) {
                $this->selectedChat->load('agent');
            }
            $this->loadMessages($chatId);
            $this->agent = $this->selectedChat->agent;
            $this->dispatch('chatChanged');
        } else {
            // Se o chat não tem agente nem modelo, remove da lista e recarrega
            session()->flash('error', 'Agente ou modelo não encontrado para este chat.');
            $this->loadChats();
            $this->selectedChat = null;
            $this->agent = null;
            $this->messages = [];
        }
    }

    /**
     * Carrega mensagens de um chat
     */
    public function loadMessages($chatId)
    {
        $ownedChat = Chats::where('uuid', $chatId)
            ->where('user_id', $this->userId)
            ->whereHas('agent', fn ($query) => $query->where('user_id', $this->userId))
            ->exists();

        if (!$ownedChat) {
            $this->messages = [];
            return;
        }

        $this->messages = Messages::where('chat_id', $chatId)
            ->with('agent')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Revalida o chat serializado pelo Livewire antes de qualquer leitura ou
     * gravação. O estado vindo do navegador não deve ser usado como fonte de
     * autorização.
     */
    private function getOwnedSelectedChat(): ?Chats
    {
        $chatUuid = data_get($this->selectedChat, 'uuid');
        if (!$chatUuid) {
            return null;
        }

        return Chats::where('uuid', $chatUuid)
            ->where('user_id', $this->userId)
            ->whereHas('agent', fn ($query) => $query->where('user_id', $this->userId))
            ->with('agent')
            ->first();
    }

    /**
     * Abre modal para criar novo chat
     */
    public function openCreateModal()
    {
        try {
            // Verificar se o usuário pode usar modelo (tem plano ativo OU não tem nenhum agente)
            $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;
            $userAgentsCount = Agents::where('user_id', $this->userId)
                ->where('status', '!=', 'draft')
                ->count();
            $canUseModel = $hasActivePlan || $userAgentsCount === 0;

            // Recarregar agentes para garantir que estão atualizados
            $agents = Agents::where('user_id', $this->userId)
                ->where('status', 'enabled')
                ->orderBy('name', 'asc')
                ->get();

            $this->agents = $agents;

            $this->showCreateModal = true;
            $this->selectedAgentId = null; // Resetar seleção
            $this->selectedModel = null; // Resetar seleção de modelo

            // Se não pode usar modelo, forçar modo agente
            if (!$canUseModel) {
                $this->chatMode = 'agent';
            } else {
                $this->chatMode = 'agent'; // Resetar para modo agente (padrão)
            }

            // Disparar evento para JavaScript
            $this->dispatch('modalOpened');
        } catch (\Exception $e) {
            Log::error('PersonalChatController: Erro ao abrir modal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            session()->flash('error', 'Erro ao abrir modal: ' . $e->getMessage());
        }
    }

    /**
     * Fecha modal de criação
     */
    public function closeCreateModal()
    {
        $this->showCreateModal = false;
        $this->selectedAgentId = null;
        $this->selectedModel = null;
        $this->chatMode = 'agent';

        // Disparar evento para JavaScript
        $this->dispatch('modalClosed');
    }

    /**
     * Regras de validação
     */
    protected function rules()
    {
        $rules = [];

        if ($this->chatMode === 'agent') {
            $rules['selectedAgentId'] = 'required|exists:agents,uuid';
        } elseif ($this->chatMode === 'model') {
            $rules['selectedModel'] = [
                'required',
                'string',
                Rule::in(AiModelCatalog::keys()),
            ];
        }

        return $rules;
    }

    /**
     * Mensagens de validação customizadas
     */
    protected function messages()
    {
        return [
            'selectedAgentId.required' => 'Por favor, selecione um agente para criar o chat.',
            'selectedAgentId.exists' => 'O agente selecionado não existe ou não está disponível.',
            'selectedModel.required' => 'Por favor, selecione um modelo para criar o chat.',
        ];
    }

    /**
     * Cria novo chat com agente ou modelo selecionado
     */
    public function createChat()
    {
        try {
            // Verificar se o usuário pode usar modelo (tem plano ativo OU não tem nenhum agente)
            $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;
            $userAgentsCount = Agents::where('user_id', $this->userId)
                ->where('status', '!=', 'draft')
                ->count();
            $canUseModel = $hasActivePlan || $userAgentsCount === 0;

            // Bloquear criação de chat com modelo se não tiver plano ativo e já tiver 1 agente
            if ($this->chatMode === 'model' && !$canUseModel) {
                session()->flash('error', 'Você não possui plano ativo e já tem 1 agente criado. Use apenas o agente existente para criar chats ou atualize seu plano.');
                $this->chatMode = 'agent'; // Forçar modo agente
                return;
            }

            // Validar dados
            try {
                $this->validate();
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::warning('PersonalChatController: Validação falhou', [
                    'errors' => $e->errors()
                ]);
                throw $e; // Re-throw para o Livewire exibir os erros
            }

            $agent = null;
            $selectedModel = null;

            if ($this->chatMode === 'agent') {
                // Modo agente: verificar se agente foi selecionado
                if (empty($this->selectedAgentId)) {
                    session()->flash('error', 'Selecione um agente para criar o chat.');
                    Log::warning('PersonalChatController: Agente não selecionado');
                    $this->addError('selectedAgentId', 'Por favor, selecione um agente.');
                    return;
                }

                $agent = Agents::where('uuid', $this->selectedAgentId)
                    ->where('user_id', $this->userId)
                    ->where('status', 'enabled')
                    ->first();

                if (!$agent) {
                    session()->flash('error', 'Agente não encontrado ou não disponível.');
                    Log::warning('PersonalChatController: Agente não encontrado', [
                        'selected_agent_id' => $this->selectedAgentId,
                        'user_id' => $this->userId
                    ]);
                    return;
                }
            } elseif ($this->chatMode === 'model') {
                // Modo modelo: criar/usar Agent específico para o modelo selecionado
                if (empty($this->selectedModel)) {
                    session()->flash('error', 'Selecione um modelo para criar o chat.');
                    Log::warning('PersonalChatController: Modelo não selecionado');
                    $this->addError('selectedModel', 'Por favor, selecione um modelo.');
                    return;
                }

                $selectedModel = AiModelCatalog::normalize($this->selectedModel);

                // Procurar por Agent existente para este modelo e usuário
                $agent = Agents::where('user_id', $this->userId)
                    ->where('model', $selectedModel)
                    ->where('agent_type', 'model_default')
                    ->where('status', 'enabled')
                    ->where('is_personal', true)
                    ->first();

                if (!$agent) {
                    // ✅ VALIDAÇÃO: Verificar limite de agentes antes de criar
                    $hasActivePlan = $this->user->mensalidade() !== 'vencido' && $this->user->plan;
                    $existingAgentsCount = Agents::where('user_id', $this->userId)
                        ->where('status', '!=', 'draft')
                        ->count();

                    // Se não tiver plano ativo e já tiver 1 agente criado, bloquear
                    if (!$hasActivePlan && $existingAgentsCount >= 1) {
                        session()->flash('error', 'Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para usar modelos ou criar mais agentes.');
                        return;
                    }

                    // Verificar limite de agentes do plano se tiver plano ativo
                    if ($hasActivePlan && $this->user->plan) {
                        $agentLimit = $this->user->plan->agentes ?? 0;
                        if ($existingAgentsCount >= $agentLimit) {
                            session()->flash('error', 'Quantidade máxima de criação de agentes atingida. Atualize seu plano para criar mais agentes.');
                            return;
                        }
                    }

                    // Criar novo Agent específico para este modelo
                    $uuid = new Uuid();
                    $agentId = (string) $uuid->limit(8);

                    // Verificar se UUID já existe
                    $existingAgent = Agents::where('uuid', $agentId)->first();
                    if ($existingAgent) {
                        $uuid = new Uuid();
                        $agentId = (string) $uuid->limit(8);
                    }

                    $agent = Agents::create([
                        'uuid' => $agentId,
                        'user_id' => $this->userId,
                        'name' => 'Modelo: ' . $selectedModel,
                        'model' => $selectedModel,
                        'channel' => 'website',
                        'agent_type' => 'model_default',
                        'status' => 'enabled',
                        'is_personal' => true,
                        'personality' => 'friendly', // Valor válido do enum
                        'training' => 'Você é um assistente de IA conversacional. Mantenha suas respostas claras, úteis e apropriadas.',
                        'instructions' => 'Você é um assistente de IA conversacional. Mantenha suas respostas claras, úteis e apropriadas.',
                        'max_characters' => 5000,
                        'context_window_size' => 15,
                        'gender' => 'neutro',
                        'autonomy' => 'moderada', // Valor válido do enum
                        'audio' => false,
                        'midia' => false,
                        'saudacao' => false,
                        'nameUser' => false,
                        'auto_followup' => false,
                        'credit_usage' => 0
                    ]);

                    Log::info('PersonalChatController: Criado novo Agent para modelo específico', [
                        'agent_id' => $agent->uuid,
                        'model' => $selectedModel,
                        'user_id' => $this->userId
                    ]);
                } else {
                    Log::info('PersonalChatController: Reutilizando Agent existente para modelo', [
                        'agent_id' => $agent->uuid,
                        'model' => $selectedModel,
                        'user_id' => $this->userId
                    ]);
                }
            } else {
                session()->flash('error', 'Modo de chat inválido.');
                return;
            }

            // Cria novo chat
            $uuid = new Uuid();
            $chatId = (string) $uuid->limit(8);

            // Verificar se já existe um chat com este UUID
            $existingChat = Chats::where('uuid', $chatId)->first();
            if ($existingChat) {
                // Gerar novo UUID se já existir
                $uuid = new Uuid();
                $chatId = (string) $uuid->limit(8);
            }

            $chatData = [
                'uuid' => $chatId,
                'user_id' => $this->userId,
                'agent_id' => $agent->uuid,
                'selected_model' => $this->chatMode === 'model' ? $selectedModel : null,
                'name' => $this->user->name ?? 'Usuário',
                'status' => 'active',
                'is_pause' => 'false', // Sempre false para personal chat
                'time' => now()->format('H:i')
            ];

            $chat = Chats::create($chatData);

            // Recarrega chats e seleciona o novo
            $this->loadChats();
            $this->selectChat($chat->uuid);
            $this->closeCreateModal();

            session()->flash('message', 'Chat criado com sucesso!');
        } catch (\Exception $e) {
            Log::error('PersonalChatController: Erro ao criar chat', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chat_mode' => $this->chatMode,
                'selected_agent_id' => $this->selectedAgentId ?? null,
                'selected_model' => $this->selectedModel ?? null
            ]);
            session()->flash('error', 'Erro ao criar chat: ' . $e->getMessage());
        }
    }

    /**
     * Envia mensagem do usuário
     */
    public function sendMessage()
    {
        // Evitar loop infinito
        if ($this->isProcessingMessage) {
            Log::warning('PersonalChatController: sendMessage chamado durante processamento, ignorando');
            return;
        }

        if (empty(trim($this->userMessage)) || !$this->selectedChat) {
            return;
        }

        $this->isProcessingMessage = true;
        $this->isSending = true;
        $userMessageText = trim($this->userMessage);
        $this->processMessage($userMessageText);
    }

    /**
     * Processa mensagem de áudio
     */
    #[On('sendAudioMessage')]
    public function sendAudioMessage($payload)
    {
        try {
            $ownedChat = $this->getOwnedSelectedChat();
            if (!$ownedChat) {
                $this->selectedChat = null;
                $this->agent = null;
                $this->messages = [];
                $this->isSending = false;
                return;
            }

            $this->selectedChat = $ownedChat;
            $this->agent = $ownedChat->agent;

            // Extrair áudio do payload
            $audio = is_array($payload) ? ($payload['audio'] ?? $payload[0]['audio'] ?? null) : null;

            if (!$audio) {
                Log::warning('PersonalChatController: Áudio não fornecido no payload', [
                    'payload_keys' => is_array($payload) ? array_keys($payload) : 'not_array'
                ]);
                $this->isSending = false;
                return;
            }

            if (!$this->selectedChat) {
                Log::warning('PersonalChatController: Chat não selecionado para enviar áudio');
                $this->isSending = false;
                return;
            }

            if (!$this->agent) {
                Log::warning('PersonalChatController: Agente não disponível para enviar áudio');
                $this->isSending = false;
                return;
            }

            $this->isSending = true;

            // Decodificar base64
            $base64Audio = $audio;
            $audioData = explode(',', $base64Audio);
            $audioContent = base64_decode(end($audioData));

            if (!$audioContent || strlen($audioContent) < 100) {
                Log::warning('PersonalChatController: Áudio muito pequeno ou inválido', [
                    'size' => strlen($audioContent ?? '')
                ]);
                $this->isSending = false;
                session()->flash('error', 'Áudio muito curto ou inválido. Tente gravar novamente.');
                return;
            }

            // Garantir que o diretório existe
            $audioDir = public_path('assets/audios');
            if (!is_dir($audioDir)) {
                mkdir($audioDir, 0755, true);
            }

            // Salvar arquivo de áudio
            $audioUser = 'audios/' . uniqid('audio_') . '.webm';
            $filePath = public_path('assets/' . $audioUser);

            $bytesWritten = file_put_contents($filePath, $audioContent);

            if ($bytesWritten === false) {
                throw new \Exception('Falha ao salvar arquivo de áudio');
            }

            // Transcrever áudio
            $contextController = app(ContextController::class);
            $userMessageText = $contextController->transcribeAudio($filePath, $this->agent);

            if (!$userMessageText || trim($userMessageText) === '') {
                Log::warning('PersonalChatController: Transcrição retornou vazia');
                $this->isSending = false;
                session()->flash('error', 'Não foi possível transcrever o áudio. Tente falar mais claramente.');
                return;
            }

            // Processar mensagem transcrita
            $this->processMessage($userMessageText, $audioUser);
        } catch (\Exception $e) {
            Log::error('PersonalChatController: Erro ao processar áudio', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->isSending = false;
            session()->flash('error', 'Erro ao processar áudio: ' . $e->getMessage());
        }
    }

    /**
     * Processa mensagem (texto ou áudio transcrito)
     */
    private function processMessage(string $userMessageText, ?string $audioUser = null)
    {
        try {
            $chat = $this->getOwnedSelectedChat();
            if (!$chat) {
                $this->selectedChat = null;
                $this->agent = null;
                $this->messages = [];
                $this->isSending = false;
                $this->isProcessingMessage = false;
                return;
            }

            $this->selectedChat = $chat;
            $this->agent = $chat->agent;

            // PERSONAL CHAT: NÃO VERIFICA is_pause - sempre permite envio

            // 1. ATUALIZA ÚLTIMA MENSAGEM E SALVA MENSAGEM DO USUÁRIO IMEDIATAMENTE
            $chat->update([
                'last_message' => $userMessageText,
                'time' => now()->format('H:i')
            ]);

            Messages::create([
                'chat_id' => $chat->uuid,
                'agent_id' => $chat->agent_id,
                'name' => 'user',
                'content' => $userMessageText,
                'direction' => 'put',
                'audio' => $audioUser,
                'credit_usage' => 0  // Será atualizado após generateResponse
            ]);

            // Limpar campos e recarregar mensagens para mostrar a mensagem do usuário
            $originalMessage = $userMessageText;
            $this->reset('userMessage');
            $this->loadMessages($chat->uuid);

            // Disparar evento para scroll automático
            $this->dispatch('userMessageSent');

            // Resetar flags de processamento antes de iniciar nova resposta
            $this->isProcessingResponse = false;
            $this->lastProcessedMessage = null;
            $this->lastResponseTimestamp = null;

            // 2. MOSTRAR INDICADOR DE "DIGITANDO"
            $this->isAgentTyping = true;
            $this->typingMessage = 'Digitando...';
            $this->dispatch('agentStartedTyping');

            // 3. PROCESSAR RESPOSTA DO AGENTE COM DELAY NATURAL
            $this->processAgentResponseWithDelay($originalMessage, $chat, $audioUser);
        } catch (\Exception $e) {
            $this->isAgentTyping = false;
            $this->typingMessage = '';
            $this->isSending = false;
            $this->isProcessingMessage = false;

            Log::error('PersonalChatController: Erro ao processar mensagem', [
                'error' => $e->getMessage(),
                'chat_id' => $this->selectedChat->uuid ?? null
            ]);

            $this->dispatch('agentStoppedTyping');
        }
    }

    /**
     * Processa a resposta do agente com delay simulado
     */
    private function processAgentResponseWithDelay(string $userMessageText, $chat, ?string $audioUser = null)
    {
        try {
            $chatId = $chat->uuid;

            // Disparar evento para JavaScript processar com delay
            $this->dispatch('processAgentResponseDelayed', [
                'userMessage' => $userMessageText,
                'chatId' => $chatId,
                'audioUser' => $audioUser
            ]);
        } catch (\Exception $e) {
            $this->isAgentTyping = false;
            $this->typingMessage = '';
            $this->isSending = false;
            $this->isProcessingMessage = false;

            Log::error('PersonalChatController: Erro ao processar resposta do agente', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chat_id' => $chat->uuid ?? null
            ]);

            // Criar mensagem de erro
            Messages::create([
                'chat_id' => $chat->uuid,
                'agent_id' => $chat->agent_id,
                'name' => 'agente',
                'content' => 'Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente.',
                'direction' => 'output',
                'credit_usage' => 0
            ]);

            $this->loadMessages($chat->uuid);
            $this->dispatch('agentStoppedTyping');
        }
    }

    /**
     * Método chamado pelo JavaScript após o delay simulado
     */
    public function processAgentResponseNow($userMessage, $audioUser = null)
    {
        try {
            $chat = $this->getOwnedSelectedChat();
            if (!$chat) {
                $this->selectedChat = null;
                $this->agent = null;
                $this->messages = [];
                $this->isAgentTyping = false;
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;
                return;
            }

            $this->selectedChat = $chat;
            $this->agent = $chat->agent;

            // Proteção contra múltiplas chamadas
            $messageHash = md5($userMessage . ($audioUser ?? '') . $chat->uuid);
            $currentTimestamp = now()->timestamp;

            // Verificar se já está processando
            if ($this->isProcessingResponse) {
                return;
            }

            // Verificar se a mesma mensagem já foi processada recentemente (últimos 5 segundos)
            if ($this->lastProcessedMessage === $messageHash) {
                $timeSinceLastResponse = $this->lastResponseTimestamp ? ($currentTimestamp - $this->lastResponseTimestamp) : 999;

                if ($timeSinceLastResponse < 5) {
                    Log::warning('PersonalChatController: Mensagem já foi processada recentemente, ignorando chamada duplicada', [
                        'user_message' => substr($userMessage ?? '', 0, 50),
                        'message_hash' => $messageHash,
                        'seconds_since_last' => $timeSinceLastResponse
                    ]);
                    return;
                }
            }

            // LOCK DE CACHE: Usar lock de cache para garantir exclusão mútua
            // Isso previne que duas requisições simultâneas processem a mesma mensagem
            $lockKey = 'personal_chat_response_' . $chat->uuid . '_' . $messageHash;
            $lock = Cache::lock($lockKey, 10); // Lock por 10 segundos
            $lockAcquired = false;

            // Tentar adquirir lock (bloqueia por até 1 segundo se não conseguir)
            try {
                $lockAcquired = $lock->get();
            } catch (\Exception $e) {
                Log::error('PersonalChatController: Erro ao tentar adquirir lock', [
                    'error' => $e->getMessage(),
                    'lock_key' => $lockKey
                ]);
            }

            if (!$lockAcquired) {
                return;
            }

            try {
                // Dentro do lock, verificar novamente se já foi processado
                if ($this->isProcessingResponse) {
                    $lock->release();
                    $lockAcquired = false;
                    return;
                }

                // Marcar como processando
                $this->isProcessingResponse = true;
                $this->lastProcessedMessage = $messageHash;
                $this->lastResponseTimestamp = $currentTimestamp;
            } catch (\Exception $e) {
                Log::error('PersonalChatController: Erro ao processar dentro do lock', [
                    'error' => $e->getMessage(),
                    'message_hash' => $messageHash
                ]);
                if ($lockAcquired) {
                    $lock->release();
                    $lockAcquired = false;
                }
                return;
            }

            if (!$this->agent) {
                $this->isAgentTyping = false;
                $this->typingMessage = '';
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;

                // Liberar lock antes de retornar
                if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                    $lock->release();
                    $lockAcquired = false;
                }
                return;
            }

            // VERIFICAÇÃO CRÍTICA: Verificar ANTES de gerar a resposta se já existe uma mensagem recente
            // Isso previne duplicação caso o método seja chamado duas vezes quase simultaneamente
            $recentResponse = Messages::where('chat_id', $chat->uuid)
                ->where('direction', 'output')
                ->where('agent_id', $chat->agent_id)
                ->where('created_at', '>', now()->subSeconds(5)) // Últimos 5 segundos
                ->orderBy('created_at', 'desc')
                ->first();

            if ($recentResponse) {
                Log::warning('PersonalChatController: Resposta já foi criada recentemente, ignorando processamento duplicado', [
                    'existing_message_id' => $recentResponse->id,
                    'created_at' => $recentResponse->created_at,
                    'seconds_ago' => now()->diffInSeconds($recentResponse->created_at),
                    'message_hash' => $messageHash
                ]);

                // Resetar flags e retornar
                $this->isAgentTyping = false;
                $this->typingMessage = '';
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;
                $this->loadMessages($chat->uuid);
                $this->dispatch('agentStoppedTyping');
                $this->dispatch('agentMessageReceived');

                // Liberar lock antes de retornar
                if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                    $lock->release();
                    $lockAcquired = false;
                }
                return;
            }

            // Atualizar mensagem de digitação
            $this->typingMessage = 'Pensando na melhor resposta...';

            // Gera resposta do agente usando sistema de memória contextual
            // O agent já tem o modelo correto definido (tanto para agentes normais quanto para model_default)
            $contextController = app(ContextController::class);
            $response = $contextController->generateResponse($chat, $this->agent, $userMessage);
            $agentMessage = $response['message'] ?? 'Desculpe, não consegui gerar uma resposta.';

            // ✅ Separar tokens de input e output
            $promptTokens = $response['prompt_tokens'] ?? 0;
            $completionTokens = $response['completion_tokens'] ?? 0;

            // Processa captura de nome
            if ($this->processUserNameCapture($chat, $response)) {
                $this->loadMessages($chat->uuid);
                $this->isAgentTyping = false;
                $this->typingMessage = '';
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;
                $this->dispatch('agentStoppedTyping');
                $this->dispatch('agentMessageReceived');
                $this->loadChats();

                // Liberar lock antes de retornar
                if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                    $lock->release();
                    $lockAcquired = false;
                }

                // ✅ Atualizar mensagem do usuário com prompt_tokens
                $lastUserMessage = Messages::where('chat_id', $chat->uuid)
                    ->where('direction', 'put')
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastUserMessage && $promptTokens > 0) {
                    $lastUserMessage->update(['credit_usage' => $promptTokens]);
                }

                return;
            }

            $pdfProcessing = $this->processPdfPlaceholder($agentMessage);
            $agentMessage = $pdfProcessing['message'];

            // Verificação FINAL antes de salvar: verificar novamente se outra thread criou a mensagem
            $recentResponseCheck = Messages::where('chat_id', $chat->uuid)
                ->where('direction', 'output')
                ->where('agent_id', $chat->agent_id)
                ->where('created_at', '>', now()->subSeconds(2)) // Últimos 2 segundos
                ->orderBy('created_at', 'desc')
                ->first();

            if ($recentResponseCheck) {

                // Resetar flags e retornar
                $this->isAgentTyping = false;
                $this->typingMessage = '';
                $this->isProcessingResponse = false;
                $this->loadMessages($chat->uuid);
                $this->dispatch('agentStoppedTyping');
                $this->dispatch('agentMessageReceived');

                // Liberar lock antes de retornar
                if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                    $lock->release();
                    $lockAcquired = false;
                }
                return;
            }

            // Salva mensagem do agente usando transação para garantir atomicidade
            try {
                DB::transaction(function () use ($chat, $agentMessage, $completionTokens, $promptTokens) {
                    // Verificação FINAL dentro da transação antes de criar
                    $finalCheck = Messages::where('chat_id', $chat->uuid)
                        ->where('direction', 'output')
                        ->where('agent_id', $chat->agent_id)
                        ->where('created_at', '>', now()->subSeconds(2))
                        ->lockForUpdate() // Lock de linha para evitar condições de corrida
                        ->first();

                    if ($finalCheck) {
                        Log::warning('PersonalChatController: Mensagem criada durante transação, abortando', [
                            'existing_message_id' => $finalCheck->id
                        ]);
                        throw new \Exception('Mensagem já existe');
                    }

                    // Criar mensagem dentro da transação
                    Messages::create([
                        'chat_id' => $chat->uuid,
                        'agent_id' => $chat->agent_id,
                        'name' => 'agente',
                        'content' => $agentMessage,
                        'direction' => 'output',
                        'credit_usage' => $completionTokens  // ✅ Usar completion_tokens
                    ]);

                    // Créditos já foram debitados pelo ContextController
                });

                // ✅ Atualizar mensagem do usuário com prompt_tokens
                $lastUserMessage = Messages::where('chat_id', $chat->uuid)
                    ->where('direction', 'put')
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastUserMessage && $promptTokens > 0) {
                    $lastUserMessage->update(['credit_usage' => $promptTokens]);
                }
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Mensagem já existe') !== false) {
                    // Mensagem já foi criada, apenas recarregar
                    Log::info('PersonalChatController: Mensagem já existe, recarregando');
                    $this->isAgentTyping = false;
                    $this->typingMessage = '';
                    $this->isProcessingResponse = false;
                    $this->loadMessages($chat->uuid);
                    $this->dispatch('agentStoppedTyping');
                    $this->dispatch('agentMessageReceived');

                    if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                        $lock->release();
                        $lockAcquired = false;
                    }
                    return;
                }
                throw $e; // Re-throw se for outro erro
            }

            // Parar indicador de digitação
            $this->isAgentTyping = false;
            $this->typingMessage = '';

            // Recarregar mensagens e disparar eventos
            $this->loadMessages($chat->uuid);
            $this->dispatch('agentStoppedTyping');
            $this->dispatch('agentMessageReceived');

            // Recarrega chats para atualizar preview
            $this->loadChats();

            // Resetar flag de processamento após sucesso
            $this->isProcessingResponse = false;
        } catch (\Exception $e) {
            Log::error('PersonalChatController: Erro ao processar resposta do agente', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chat_id' => $this->selectedChat->uuid ?? null,
                'user_message' => substr($userMessage ?? '', 0, 100)
            ]);

            // Criar mensagem de erro apenas se ainda não foi criada
            if ($this->selectedChat && !$this->isProcessingResponse) {
                try {
                    // Verificar se já existe uma mensagem de erro recente
                    $recentError = Messages::where('chat_id', $this->selectedChat->uuid)
                        ->where('direction', 'output')
                        ->where('content', 'like', '%erro%')
                        ->where('created_at', '>', now()->subSeconds(5))
                        ->first();

                    if (!$recentError) {
                        Messages::create([
                            'chat_id' => $this->selectedChat->uuid,
                            'agent_id' => $this->selectedChat->agent_id,
                            'name' => 'agente',
                            'content' => 'Desculpe, ocorreu um erro ao processar sua mensagem. Tente novamente.',
                            'direction' => 'output',
                            'credit_usage' => 0
                        ]);

                        $this->loadMessages($this->selectedChat->uuid);
                    }
                } catch (\Exception $e2) {
                    Log::error('PersonalChatController: Erro ao criar mensagem de erro', [
                        'error' => $e2->getMessage()
                    ]);
                }
            }

            $this->isAgentTyping = false;
            $this->typingMessage = '';
            $this->dispatch('agentStoppedTyping');
        } finally {
            // Sempre garantir que os flags sejam resetados E o lock seja liberado
            $this->isSending = false;
            $this->isProcessingMessage = false;
            $this->isProcessingResponse = false;

            // Liberar lock se foi adquirido
            if (isset($lockAcquired) && $lockAcquired && isset($lock)) {
                try {
                    $lock->release();
                    $lockAcquired = false;
                } catch (\Exception $e) {
                    Log::warning('PersonalChatController: Erro ao liberar lock', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Processa captura de nome do usuário
     */
    private function processUserNameCapture($chat, $response): bool
    {
        if (!filter_var($this->agent->nameUser ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if (preg_match('/nome_do_usuario:\s*([^\n]+)/i', $response['message'], $match)) {
            $name = trim($match[1]);

            if (preg_match('/^[a-zA-ZÀ-ÿ\s]{2,50}$/u', $name)) {
                $chat->name = $name;
                $chat->save();

                $this->saveUserNameToKnowledgeBase($chat, $name);

                $friendlyResponse = "Prazer em conhecê-lo, {$name}! Como posso ajudá-lo hoje?";

                Messages::create([
                    'chat_id' => $chat->uuid,
                    'agent_id' => $chat->agent_id,
                    'name' => 'agente',
                    'content' => $friendlyResponse,
                    'direction' => 'output',
                    'credit_usage' => $response['completion_tokens'] ?? 0  // ✅ Usar completion_tokens
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Salva nome no knowledge_base
     */
    private function saveUserNameToKnowledgeBase($chat, $name)
    {
        try {
            $streamRepository = app(\App\Http\Controllers\Knowledge\Repositories\StreamRepository::class);
            $session = $streamRepository->getOrCreateStream($chat);

            $knowledge = $session->knowledge_base ?? [];
            if (is_string($knowledge)) {
                $decoded = json_decode($knowledge, true);
                $knowledge = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            }

            if (!isset($knowledge['informacoes_pessoais'])) {
                $knowledge['informacoes_pessoais'] = [];
            }
            $knowledge['informacoes_pessoais']['nome'] = $name;

            $session->knowledge_base = $knowledge;
            $streamRepository->save($session);
        } catch (\Exception $e) {
            Log::warning('Erro ao salvar nome no knowledge_base: ' . $e->getMessage());
        }
    }

    /**
     * Processa placeholder de PDF
     */
    private function processPdfPlaceholder(?string $agentMessage): array
    {
        if (empty($agentMessage)) {
            return ['message' => '', 'pdf_sent' => false];
        }

        $pattern = '/(\{\{\s*enviar_?pdf\s*\}\}|\[\[\s*enviar_?pdf\s*\]\]|enviar_?pdf\s*:?.*)/i';
        $pdfDetected = preg_match($pattern, $agentMessage, $matches, PREG_OFFSET_CAPTURE);

        if (!$pdfDetected) {
            return ['message' => trim($agentMessage), 'pdf_sent' => false];
        }

        $sanitized = substr($agentMessage, 0, $matches[0][1]);
        $sanitized = trim(preg_replace("/\n{2,}/", "\n\n", $sanitized));

        $pdfUrl = url('pdf/book_view.pdf');
        $ackMessage = "Acabei de te enviar o PDF com todas as informações. Caso não abra, me avise, por favor.";
        $linkMessage = "Link direto para baixar: {$pdfUrl}";

        $finalMessage = $sanitized !== ''
            ? $sanitized . "\n\n" . $ackMessage . "\n" . $linkMessage
            : $ackMessage . "\n" . $linkMessage;

        return ['message' => trim($finalMessage), 'pdf_sent' => true];
    }

    /**
     * Recebe mensagem via evento
     */
    #[On('messageReceived')]
    public function messageReceived($payload)
    {
        $data = $payload;
        if (is_array($payload) && isset($payload['payload']) && is_array($payload['payload'])) {
            $data = $payload['payload'];
        }
        if (!is_array($data) || !isset($data['chat_id'])) {
            return;
        }
        if ($this->selectedChat && $data['chat_id'] === $this->selectedChat->uuid) {
            $this->loadMessages($data['chat_id']);
        }
    }

    /**
     * Atualiza mensagem de digitação (chamado pelo JavaScript)
     */
    public function updateTypingMessage($message = null)
    {
        if ($message === null) {
            return;
        }

        // Se receber um array/objeto, extrair a mensagem
        if (is_array($message)) {
            $message = $message['message'] ?? $message[0]['message'] ?? null;
        }

        if ($this->isAgentTyping && $message) {
            $this->typingMessage = $message;
            // Disparar evento para scroll
            $this->dispatch('typingMessageUpdated');
        }
    }

    /**
     * Método de fallback: Processa resposta diretamente sem delay
     * Pode ser chamado diretamente caso o JavaScript falhe
     */
    public function processResponseDirectly($userMessage = null, $audioUser = null)
    {
        try {
            $ownedChat = $this->getOwnedSelectedChat();
            if (!$ownedChat) {
                $this->selectedChat = null;
                $this->agent = null;
                $this->messages = [];
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;
                return;
            }

            $this->selectedChat = $ownedChat;
            $this->agent = $ownedChat->agent;

            // Se não recebeu parâmetros, tentar obter da última mensagem
            if (!$userMessage) {
                $lastMessage = Messages::where('chat_id', $ownedChat->uuid)
                    ->where('direction', 'put')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($lastMessage) {
                    $userMessage = $lastMessage->content;
                    $audioUser = $lastMessage->audio;
                }
            }

            if (!$userMessage || !$this->selectedChat) {
                Log::warning('PersonalChatController: processResponseDirectly chamado sem parâmetros válidos');
                $this->isAgentTyping = false;
                $this->typingMessage = '';
                $this->isSending = false;
                $this->isProcessingMessage = false;
                $this->isProcessingResponse = false;
                return;
            }

            // Verificar se já está processando (mesma proteção do processAgentResponseNow)
            $messageHash = md5($userMessage . ($audioUser ?? '') . ($this->selectedChat->uuid ?? ''));

            if ($this->isProcessingResponse) {
                Log::warning('PersonalChatController: processResponseDirectly ignorado - já está processando');
                return;
            }

            if ($this->lastProcessedMessage === $messageHash) {
                Log::warning('PersonalChatController: processResponseDirectly ignorado - mensagem já processada');
                return;
            }

            Log::info('PersonalChatController: processResponseDirectly chamado', [
                'user_message_length' => strlen($userMessage),
                'message_hash' => $messageHash
            ]);

            // Processar resposta diretamente (já tem proteção interna)
            $this->processAgentResponseNow($userMessage, $audioUser);
        } catch (\Exception $e) {
            Log::error('PersonalChatController: Erro em processResponseDirectly', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->isAgentTyping = false;
            $this->typingMessage = '';
            $this->isSending = false;
            $this->isProcessingMessage = false;
            $this->isProcessingResponse = false;
            $this->dispatch('agentStoppedTyping');
        }
    }
}
