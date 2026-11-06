<?php

namespace App\Http\Livewire\Whatsapp;

use App\Helpers\Uuid;
use App\Models\WhatsappInstance;
use App\Models\Agents;
use App\Services\WhatsappProviderService;
use App\Support\AiModelCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Attributes\On;

class WhatsappInstancesController extends Component
{
    public $user;
    public $instances = [];
    public $showQrModal = false;
    public $selectedInstance = null;
    public $qrCodeData = null;
    public $connectingInstance = null;
    public $persistentQrCode = null;
    public $instanceMode = 'agent'; // 'agent' ou 'api'
    public $agentType = 'existing'; // 'existing' ou 'model' (para instâncias de agente)
    public $agent_id = '';
    public $selectedModel = ''; // Para quando usar modelo diretamente
    public $name = '';
    public $description = '';
    public $webhook_url = '';
    public $webhook_enabled = false;
    protected $listeners = [
        'instanceStatusUpdated',
        'checkInstanceStatus'
    ];

    public function mount()
    {
        view()->share('title', 'Instâncias WhatsApp');
        $this->user = Auth::user();
        $this->resetStuckInstances();
        $this->loadInstances();
    }

    /**
     * ✅ SIMPLIFICADO: Extrai a string base64 do QR code
     * Agora o banco sempre armazena apenas a string base64 pura (sem prefixo)
     * Se vier como array JSON, extrai apenas o valor 'qr' ou primeiro elemento válido
     */
    private function extractQrCodeString($qrCode)
    {
        if (empty($qrCode)) {
            return null;
        }

        // ✅ Se for array (JSON decodificado), extrair apenas o valor 'qr' ou primeiro elemento
        if (is_array($qrCode)) {
            if (isset($qrCode['qr']) && is_string($qrCode['qr'])) {
                $qrCode = $qrCode['qr'];
            } elseif (isset($qrCode[0]) && is_string($qrCode[0])) {
                $qrCode = $qrCode[0];
            } else {
                return null; // Array inválido
            }
        }

        // ✅ Se for objeto, converter para array primeiro
        if (is_object($qrCode)) {
            $qrArray = (array) $qrCode;
            if (isset($qrArray['qr']) && is_string($qrArray['qr'])) {
                $qrCode = $qrArray['qr'];
            } else {
                return null; // Objeto inválido
            }
        }

        // ✅ Agora $qrCode deve ser string
        if (!is_string($qrCode) || empty($qrCode)) {
            return null;
        }

        // ✅ Se for JSON stringificado, decodificar primeiro
        if (strpos($qrCode, '{') === 0 || strpos($qrCode, '[') === 0) {
            $decoded = json_decode($qrCode, true);
            if (is_array($decoded)) {
                if (isset($decoded['qr']) && is_string($decoded['qr'])) {
                    $qrCode = $decoded['qr'];
                } elseif (isset($decoded[0]) && is_string($decoded[0])) {
                    $qrCode = $decoded[0];
                } else {
                    return null;
                }
            }
        }

        // ✅ Remover prefixo data:image se existir (caso venha do frontend)
        if (strpos($qrCode, 'data:image') === 0) {
            $parts = explode(',', $qrCode, 2);
            return $parts[1] ?? null;
        }

        // ✅ Retornar string base64 pura (já está no formato correto do banco)
        return $qrCode;
    }

    /**
     * Define o QR code garantindo que seja sempre string ou null
     */
    private function setQrCodeData($qrCode)
    {
        $this->qrCodeData = $this->extractQrCodeString($qrCode);
    }

    /**
     * Define o QR code persistente garantindo que seja sempre string ou null
     */
    private function setPersistentQrCode($qrCode)
    {
        $this->persistentQrCode = $this->extractQrCodeString($qrCode);
    }

    /**
     * Restaura o estado do QR code se necessário
     */
    private function restoreQrCodeState()
    {
        if ($this->connectingInstance && !$this->qrCodeData) {
            // Tentar recuperar QR code da instância no banco
            $instance = WhatsappInstance::where('uuid', $this->connectingInstance)
                ->where('user_id', $this->user->uuid)
                ->first();

            if ($instance && $instance->qr_code) {
                $instanceQr = $this->extractQrCodeString($instance->qr_code);
                $this->setQrCodeData($instanceQr);
                $this->setPersistentQrCode($instanceQr);
            } elseif ($this->persistentQrCode) {
                // Usar QR code persistente
                $this->setQrCodeData($this->persistentQrCode);
            }
        }
    }

    /**
     * Reseta instâncias que ficaram travadas em "connecting" sem QR code
     */
    private function resetStuckInstances()
    {
        try {
            // Reseta instâncias que estão em "connecting" há mais de 5 minutos sem QR code
            $stuckInstances = WhatsappInstance::where('user_id', $this->user->uuid)
                ->where('status', 'connecting')
                ->where(function ($query) {
                    $query->whereNull('qr_code')
                        ->orWhere('qr_code', '');
                })
                ->where('updated_at', '<', now()->subMinutes(5))
                ->get();

            foreach ($stuckInstances as $instance) {
                $instance->update([
                    'status' => 'disconnected',
                    'qr_code' => null,
                    'error_message' => 'Conexão expirada. Tente conectar novamente.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erro ao resetar instâncias travadas', ['error' => $e->getMessage()]);
        }
    }

    public function loadInstances()
    {
        $this->instances = WhatsappInstance::where('user_id', $this->user->uuid)
            ->with('agent') // Carrega agente relacionado
            ->orderBy('status', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->toArray();
    }

    public function render()
    {
        // Restaurar estado do QR code se necessário
        $this->restoreQrCodeState();

        $viewData = [
            'agents' => Agents::where('user_id', $this->user->uuid)
                ->where('status', 'enabled')
                // Agentes pessoais são reservados ao Chat Pessoal, onde o
                // comportamento de mensagens para si mesmo é intencional.
                // Não os oferecer para uma instância WhatsApp evita que uma
                // instância de atendimento seja criada com o filtro pessoal.
                ->where('is_personal', false)
                ->orderBy('name', 'asc')
                ->get(),
            'models' => $this->loadAvailableModels()
        ];

        return view('livewire.whatsapp.whatsapp-instances', $viewData);
    }

    /**
     * Carrega modelos disponíveis das APIs configuradas
     */
    private function loadAvailableModels()
    {
        return AiModelCatalog::options();
    }

    public function openCreateModal()
    {
        // Verificar se o usuário tem plano ativo
        $hasActivePlan = $this->user->plan && $this->user->mensalidade() !== 'vencido';

        // Verificar limite de instâncias baseado no plano
        if ($hasActivePlan) {
            $currentInstancesCount = WhatsappInstance::where('user_id', $this->user->uuid)
                ->where('is_trial', false) // Não conta instâncias de teste
                ->count();

            $maxInstances = $this->user->plan->instances ?? 0;

            if ($currentInstancesCount >= $maxInstances) {
                session()->flash('error', "Quantidade máxima de criação de instâncias atingida ({$maxInstances}). Faça upgrade do seu plano para criar mais instâncias.");
                $this->dispatch('redirectToPlans');
                return;
            }
        } else {
            // Para usuários sem plano ativo, verificar limite de instâncias de teste
            $trialInstancesCount = WhatsappInstance::where('user_id', $this->user->uuid)
                ->where('is_trial', true)
                ->where(function ($query) {
                    $query->whereNull('trial_expires_at')
                        ->orWhere('trial_expires_at', '>', now());
                })
                ->count();

            if ($trialInstancesCount >= 1) {
                session()->flash('error', 'Você já possui uma instância de teste ativa. Para criar mais instâncias, assine um plano.');
                $this->dispatch('redirectToPlans');
                return;
            }
        }

        $this->resetForm();
        $this->dispatch('openCreateModal');
    }

    public function createInstance()
    {
        // Validação para instância de agente
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ];

        if ($this->agentType === 'existing') {
            $rules['agent_id'] = 'required|string|exists:agents,uuid';
        } elseif ($this->agentType === 'model') {
            $rules['selectedModel'] = [
                'required',
                'string',
                Rule::in(AiModelCatalog::keys()),
            ];
        }

        $this->validate($rules);

        try {
            // Verifica se o usuário tem um plano ativo
            $hasActivePlan = $this->user->plan && $this->user->mensalidade() !== 'vencido';

            $agent = null;
            $instanceId = '';
            $instanceName = $this->name;

            if ($this->agentType === 'existing') {
                $agent = Agents::where('uuid', $this->agent_id)
                    ->where('user_id', $this->user->uuid)
                    ->where('status', 'enabled')
                    ->where('is_personal', false)
                    ->first();

                if (!$agent) {
                    session()->flash('error', 'Agente não encontrado ou não disponível.');
                    return;
                }

                // Gera instance_id único baseado no agent_id
                $instanceId = 'baileys_' . substr($agent->uuid, 0, 8);

                // Verifica se já existe instância para este agente
                $existingInstance = WhatsappInstance::where('agent_id', $this->agent_id)
                    ->where('user_id', $this->user->uuid)
                    ->first();

                if ($existingInstance) {
                    session()->flash('error', 'Já existe uma instância para este agente.');
                    return;
                }

                // Define nome padrão se não informado
                if (!$instanceName) {
                    $instanceName = $agent->name;
                }
            } elseif ($this->agentType === 'model') {
                // Modo modelo: criar/usar Agent específico para o modelo selecionado
                if (empty($this->selectedModel)) {
                    session()->flash('error', 'Selecione um modelo para criar a instância.');
                    return;
                }

                $selectedModel = AiModelCatalog::normalize($this->selectedModel);

                // Procurar por Agent existente para este modelo e usuário
                $agent = Agents::where('user_id', $this->user->uuid)
                    ->where('model', $selectedModel)
                    ->where('agent_type', 'model_default')
                    ->where('status', 'enabled')
                    ->where('is_personal', false)
                    ->first();

                if (!$agent) {
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
                        'user_id' => $this->user->uuid,
                        'name' => 'Modelo: ' . $selectedModel,
                        'model' => $selectedModel,
                        'channel' => 'whatsapp',
                        'agent_type' => 'model_default',
                        'status' => 'enabled',
                        'is_personal' => false,
                        'personality' => 'friendly',
                        'training' => 'Você é um assistente de IA conversacional para WhatsApp. Mantenha suas respostas claras, úteis e apropriadas.',
                        'instructions' => 'Você é um assistente de IA conversacional para WhatsApp. Mantenha suas respostas claras, úteis e apropriadas.',
                        'max_characters' => 5000,
                        'context_window_size' => 15,
                        'gender' => 'neutro',
                        'autonomy' => 'moderada',
                        'audio' => false,
                        'midia' => false,
                        'saudacao' => false,
                        'nameUser' => false,
                        'auto_followup' => false,
                        'credit_usage' => 0
                    ]);

                    Log::info('WhatsappInstancesController: Criado novo Agent para modelo específico', [
                        'agent_id' => $agent->uuid,
                        'model' => $selectedModel,
                        'user_id' => $this->user->uuid
                    ]);
                }

                // Gera instance_id único baseado no agent_id
                $instanceId = 'baileys_' . substr($agent->uuid, 0, 8);

                // Para modelos, não verificamos duplicatas já que cada modelo pode ter múltiplas instâncias
                if (!$instanceName) {
                    $instanceName = 'Modelo: ' . $selectedModel;
                }
            } else {
                session()->flash('error', 'Tipo de agente inválido.');
                return;
            }

            // Se não tem plano ativo, verifica limite de instâncias de teste
            if (!$hasActivePlan) {
                $trialInstancesCount = WhatsappInstance::where('user_id', $this->user->uuid)
                    ->where('is_trial', true)
                    ->where(function ($query) {
                        $query->whereNull('trial_expires_at')
                            ->orWhere('trial_expires_at', '>', now());
                    })
                    ->count();

                if ($trialInstancesCount >= 1) {
                    session()->flash('error', 'Você já possui uma instância de teste ativa. Para criar mais instâncias, assine um plano.');
                    return;
                }
            }

            $instanceData = [
                'uuid' => Str::limit(Str::uuid(), 8, ''),
                'user_id' => $this->user->uuid,
                'instance_id' => $instanceId,
                'provider' => 'baileys',
                'name' => $instanceName,
                'description' => $this->description,
                'status' => 'disconnected',
                'instance_type' => 'agent',
                'agent_id' => $agent->uuid,
            ];

            // Se não tem plano ativo, cria instância de teste com 48h de validade
            if (!$hasActivePlan) {
                $instanceData['is_trial'] = true;
                $instanceData['trial_expires_at'] = now()->addHours(48);
            }

            $instance = WhatsappInstance::create($instanceData);

            $this->loadInstances();
            $this->resetForm();
            $this->dispatch('closeCreateModal');

            if (!$hasActivePlan) {
                session()->flash('message', 'Instância de teste criada com sucesso! Você tem 48 horas para testá-la.');
            } else {
                session()->flash('message', 'Instância criada com sucesso!');
            }
        } catch (\Exception $e) {
            Log::error('Erro ao criar instância WhatsApp', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            session()->flash('error', 'Erro ao criar instância: ' . $e->getMessage());
        }
    }

    public function connectInstance($instanceId)
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $this->user->uuid)
            ->with('agent')
            ->first();

        if (!$instance) {
            session()->flash('error', 'Instância não encontrada.');
            return;
        }

        // 🔒 PREVENÇÃO DE RACE CONDITIONS: Bloqueio de operações simultâneas
        if ($instance->status === 'connecting' && $instance->provider !== 'zapi') {
            // Verifica se está "travado" há mais de 3 minutos
            if ($instance->updated_at && $instance->updated_at->addMinutes(3)->isPast()) {
                // Reset automático para instâncias travadas
                $instance->updateStatus('disconnected');
                $instance->update(['qr_code' => null, 'error_message' => 'Conexão resetada automaticamente.']);
            } else {
                // 🆕 PERMITIR REABRIR MODAL: Ao invés de bloquear, reabrir modal com QR existente
                // Não reiniciar conexão, apenas mostrar QR code novamente
                $this->selectedInstance = $instance->toArray();
                $this->showQrModal = true;
                $this->connectingInstance = $instance->uuid;

                // Restaurar QR code se existir
                if ($instance->qr_code) {
                    $instanceQr = $this->extractQrCodeString($instance->qr_code);
                    $this->setQrCodeData($instanceQr);
                    $this->setPersistentQrCode($instanceQr);
                } else {
                    $this->setQrCodeData(null);
                }

                $this->loadInstances();

                // 🆕 Iniciar verificação de status para fechar modal quando conectar
                $this->dispatch('startStatusCheck', $instance->uuid);

                session()->flash('message', 'Modal reaberto. Escaneie o QR Code para conectar.');
                return;
            }
        }

        // Verifica se é uma instância de teste expirada (não pode ser conectada)
        if ($instance->is_trial && $instance->isTrialExpired()) {
            session()->flash('error', 'Esta instância de teste expirou e não pode ser conectada. Assine um plano para funcionalidades completas.');
            $this->loadInstances();
            return;
        }

        if (!$instance->agent_id || !$instance->agent) {
            session()->flash('error', 'Agente não encontrado para esta instância.');
            return;
        }

        // A Z-API possui ciclo de conexão próprio: QR e status são obtidos
        // diretamente pela API, sem criar cliente Baileys em paralelo.
        if ($instance->provider === 'zapi') {
            $provider = app(WhatsappProviderService::class);
            if ($provider->isConnected($instance)) {
                $instance->refresh();
                session()->flash('message', 'Instância Z-API já está conectada.');
                $this->loadInstances();
                return;
            }

            try {
                $this->resetModalState();
                $instance->update([
                    'status' => 'connecting',
                    'qr_code' => null,
                    'error_message' => null,
                    'last_activity' => now(),
                ]);

                $qrResult = $provider->getQrCode($instance);
                if (!empty($qrResult['error'])) {
                    $instance->updateStatus('error', (string) ($qrResult['message'] ?? 'Falha ao obter QR Code da Z-API.'));
                    throw new \RuntimeException($qrResult['message'] ?? 'Falha ao obter QR Code da Z-API.');
                }

                $instance->update([
                    'status' => 'connecting',
                    'qr_code' => $this->extractQrCodeString($qrResult['qr'] ?? null),
                    'last_activity' => now(),
                ]);
                $instance->refresh();
                $this->selectedInstance = $instance->toArray();
                $this->showQrModal = true;
                $this->connectingInstance = $instance->uuid;
                $this->setQrCodeData($qrResult['qr'] ?? null);
                $this->setPersistentQrCode($qrResult['qr'] ?? null);
                $this->loadInstances();
                $this->dispatch('qrCodeReceived');
                $this->dispatch('startStatusCheck', $instance->uuid);
                return;
            } catch (\Throwable $e) {
                Log::error('Erro ao conectar instância Z-API', [
                    'instance_id' => $instance->uuid,
                    'error' => $e->getMessage(),
                ]);
                $this->resetModalState();
                $this->loadInstances();
                session()->flash('error', $e->getMessage());
                return;
            }
        }

        // Se já estiver conectado, não fazer nada
        if ($instance->status === 'connected') {
            session()->flash('message', 'Instância já está conectada.');
            $this->loadInstances();
            return;
        }

        // Se estiver em "connecting" sem QR code há mais de 2 minutos, resetar primeiro
        if (
            $instance->status === 'connecting' &&
            (!$instance->qr_code || $instance->qr_code === '') &&
            $instance->updated_at < now()->subMinutes(2)
        ) {
            $instance->updateStatus('disconnected');
            $instance->update(['qr_code' => null, 'error_message' => null]);
        }

        try {
            // Reseta estado do modal
            $this->resetModalState();

            $instance->updateStatus('connecting');
            $instance->update(['qr_code' => null, 'error_message' => null]);
            $this->loadInstances();

            // Prepara dados para conexão via Socket.io
            $this->selectedInstance = $instance->toArray();
            $this->showQrModal = true;
            $this->connectingInstance = $instance->uuid;

            // Restaurar QR code se existir na instância
            if ($instance->qr_code) {
                $instanceQr = $this->extractQrCodeString($instance->qr_code);
                $this->setQrCodeData($instanceQr);
                $this->setPersistentQrCode($instanceQr);
            } else {
                $this->setQrCodeData(null);
            }

            // Dispara evento para JavaScript conectar via Socket.io
            $this->dispatch('connectViaSocket', [
                'instanceId' => $instance->uuid,
                'agentId' => $instance->agent_id,
                'userId' => $this->user->uuid,
                'force' => true // Ação explícita do usuário: libera bloqueio de erro fatal no frontend
            ]);

            // Inicia verificação automática do status
            $this->dispatch('startStatusCheck', $instance->uuid);
        } catch (\Exception $e) {
            Log::error('Erro ao conectar instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            $instance->updateStatus('error', $e->getMessage());
            $this->resetModalState();
            $this->loadInstances();
            session()->flash('error', 'Erro ao conectar instância: ' . $e->getMessage());
        }
    }

    /**
     * Fecha o modal e reseta o estado de conexão
     */
    public function closeQrModal()
    {
        // Se estiver conectando, reseta o status para disconnected
        if ($this->connectingInstance) {
            $instance = WhatsappInstance::where('uuid', $this->connectingInstance)
                ->where('user_id', $this->user->uuid)
                ->first();

            if ($instance && $instance->status === 'connecting' && !$instance->phone_number) {
                $instance->updateStatus('disconnected');
                $instance->update(['qr_code' => null, 'error_message' => 'Conexão cancelada pelo usuário.']);
                $this->loadInstances();
            }
        }

        $this->resetModalState();

        // Dispara evento para JavaScript limpar timeouts
        $this->dispatch('modalClosed');
    }

    /**
     * STATE MACHINE: Gerencia transições de estado de forma determinística
     */
    private function transitionState($instance, $baileysStatus, $payload, $qrCode, $phoneNumber)
    {
        $updateData = [];

        switch ($baileysStatus) {
            case 'qr':
                // QR Code gerado - manter modal aberto
                $updateData['status'] = 'connecting';
                // ✅ SIMPLIFICADO: Salvar apenas a string base64 pura (sem prefixo data:image)
                // O server.js já envia apenas a string base64, então usar diretamente
                $updateData['qr_code'] = $qrCode ? $this->extractQrCodeString($qrCode) : null;
                $updateData['error_message'] = null;

                $this->setQrCodeData($qrCode);
                $this->setPersistentQrCode($qrCode);
                $this->showQrModal = true;
                $this->selectedInstance = $instance->toArray();
                $this->connectingInstance = $instance->uuid;

                break;

            case 'authenticated':
                // QR lido - manter modal aberto com feedback
                $updateData['status'] = 'connecting';
                $updateData['phone_number'] = $phoneNumber;
                $updateData['error_message'] = null;

                // ✅ MARCAR TIMESTAMP DA AUTENTICAÇÃO para contexto de handshake
                $updateData['last_auth_time'] = now();
                break;

            case 'ready':
            case 'connected':
                // WhatsApp totalmente conectado - fechar modal
                $updateData['status'] = 'connected';
                $updateData['phone_number'] = $phoneNumber;
                $updateData['connected_at'] = now();
                $updateData['error_message'] = null;

                $this->resetModalState();
                break;

            case 'disconnected':
            case 'close':
                // Desconexão - só fechar modal se não estiver em processo de autenticação
                $updateData['status'] = 'disconnected';
                $updateData['qr_code'] = null;
                $updateData['error_message'] = $payload['message'] ?? 'Conexão perdida';

                // Só fechar modal se não for a instância que está conectando
                if ($this->connectingInstance !== $instance->uuid) {
                    $this->resetModalState();
                }
                break;

            case 'error':
                // Erro - fechar modal e limpar estado
                $updateData['status'] = 'error';
                $updateData['qr_code'] = null;

                // 🛡️ TRATAMENTO INTELIGENTE DE ERROS WHATSAPP
                $errorMessage = $payload['error'] ?? $payload['message'] ?? 'Erro desconhecido';

                // Detecta bans temporários do WhatsApp
                if (
                    str_contains(strtolower($errorMessage), 'temporarily banned') ||
                    str_contains(strtolower($errorMessage), 'temporary ban') ||
                    str_contains(strtolower($errorMessage), 'block')
                ) {
                    $updateData['error_message'] = 'Conta temporariamente bloqueada pelo WhatsApp. Aguarde algumas horas e tente novamente.';
                    $updateData['status'] = 'banned'; // Status especial para bans
                }
                // Detecta problemas de rede/timeout
                elseif (
                    str_contains(strtolower($errorMessage), 'timeout') ||
                    str_contains(strtolower($errorMessage), 'network') ||
                    str_contains(strtolower($errorMessage), 'connection')
                ) {
                    $updateData['error_message'] = 'Problema de conexão. Verifique sua internet e tente novamente.';
                    // Mantém status como 'error' mas permite reconexão
                }
                // Detecta sessões corrompidas
                elseif (
                    str_contains(strtolower($errorMessage), 'corrupt') ||
                    str_contains(strtolower($errorMessage), 'invalid session') ||
                    str_contains(strtolower($errorMessage), 'decryption failed') ||
                    str_contains(strtolower($errorMessage), 'Sessão corrompida')
                ) {
                    $updateData['error_message'] = 'Sessão corrompida detectada. Clique em "Reconectar (Corrompida)" para gerar um novo QR code.';
                    $updateData['status'] = 'corrupted'; // Status especial
                } else {
                    $updateData['error_message'] = $errorMessage;
                }

                $this->resetModalState();
                Log::error('Erro na conexão WhatsApp', [
                    'agent_id' => $instance->agent_id,
                    'error_type' => $updateData['status'],
                    'error' => $errorMessage
                ]);
                break;

            case 'corrupted':
                // Sessão corrompida - manter modal fechado e aguardar reconexão manual
                $updateData['status'] = 'corrupted';
                $updateData['qr_code'] = null;
                $updateData['error_message'] = $payload['message'] ?? 'Sessão corrompida detectada - reconexão manual necessária';

                $this->resetModalState();
                Log::warning('Sessão corrompida detectada', [
                    'agent_id' => $instance->agent_id,
                    'message' => $payload['message'] ?? 'N/A'
                ]);
                break;

            default:
                // Status desconhecido - logar mas não alterar estado
                return;
        }

        $instance->update($updateData);
    }

    /**
     * Reseta o estado do modal
     */
    private function resetModalState()
    {
        // Timeout removido - não existe clearTimeout() no PHP
        $this->showQrModal = false;
        $this->selectedInstance = null;
        $this->connectingInstance = null;
        $this->qrCodeData = null;
        $this->persistentQrCode = null;
    }

    public function disconnectInstance($instanceId)
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $this->user->uuid)
            ->first();

        if (!$instance) {
            session()->flash('error', 'Instância não encontrada.');
            return;
        }

        try {
            if ($instance->provider === 'zapi') {
                $result = app(WhatsappProviderService::class)->disconnect($instance);
                if (!empty($result['error'])) {
                    throw new \RuntimeException($result['message'] ?? 'Falha ao desconectar a Z-API.');
                }
                session()->flash('message', 'Instância Z-API desconectada com sucesso!');
                $this->loadInstances();
                return;
            }

            // Dispara evento para JavaScript desconectar via Socket.io
            $this->dispatch('disconnectViaSocket', [
                'instanceId' => $instance->uuid,
                'agentId' => $instance->agent_id
            ]);

            $instance->updateStatus('disconnected');
            $instance->update([
                'phone_number' => null,
                'connected_at' => null,
                'qr_code' => null
            ]);

            session()->flash('message', 'Instância desconectada com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao desconectar instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            session()->flash('error', 'Erro ao desconectar instância: ' . $e->getMessage());
        }

        $this->loadInstances();
    }

    public function restartInstance($instanceId)
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $this->user->uuid)
            ->first();

        if (!$instance) {
            session()->flash('error', 'Instância não encontrada.');
            return;
        }

        // Verifica se é uma instância de teste expirada (não pode ser reiniciada)
        if ($instance->is_trial && $instance->isTrialExpired()) {
            session()->flash('error', 'Esta instância de teste expirou e não pode ser reiniciada. Assine um plano para funcionalidades completas.');
            $this->loadInstances();
            return;
        }

        try {
            if ($instance->provider === 'zapi') {
                $result = app(WhatsappProviderService::class)->restart($instance);
                if (!empty($result['error'])) {
                    throw new \RuntimeException($result['message'] ?? 'Falha ao reiniciar a Z-API.');
                }
                $instance->update(['status' => 'connecting', 'last_activity' => now()]);
                session()->flash('message', 'Instância Z-API reiniciada.');
                $this->loadInstances();
                return;
            }

            // ✅ Apenas dispara evento de restart (SEM desconectar antes)
            // O servidor vai desconectar temporariamente e reconectar mantendo a sessão
            $this->dispatch('restartViaSocket', [
                'instanceId' => $instance->uuid,
                'agentId' => $instance->agent_id,
                'userId' => $this->user->uuid
            ]);

            session()->flash('message', 'Instância sendo reiniciada...');
        } catch (\Exception $e) {
            Log::error('Erro ao reiniciar instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            session()->flash('error', 'Erro ao reiniciar instância: ' . $e->getMessage());
        }

        $this->loadInstances();
    }

    /**
     * Atualiza status da instância via evento do Socket.io
     */
    public function instanceStatusUpdated($data = null)
    {
        // Verificar se há dados válidos
        if (empty($data)) {
            // Dados vazios - pode ser chamada automática durante inicialização
            // Não logar warning para evitar poluição de logs
            return;
        }

        // Extrair variáveis dos dados primeiro
        $instanceId = $data['instanceId'] ?? $data['instance_id'] ?? null;
        $agentId = $data['agent_id'] ?? $data['agentId'] ?? null;
        $status = $data['status'] ?? null;
        $phoneNumber = $data['phone_number'] ?? $data['phoneNumber'] ?? null;
        $qrCode = $data['qr_code'] ?? $data['qrCode'] ?? null;

        // ✅ SIMPLIFICADO: Normalizar QR code imediatamente para evitar problemas de tipo
        // O server.js agora sempre envia data URL completa via socket, mas salva apenas base64 no banco
        // Precisamos extrair apenas a string base64 para salvar no banco
        if ($qrCode !== null) {
            // Se for data URL completa, extrair apenas base64
            if (is_string($qrCode) && strpos($qrCode, 'data:image') === 0) {
                $parts = explode(',', $qrCode, 2);
                $qrCode = $parts[1] ?? $qrCode; // Pegar apenas a parte base64
            } else {
                // Se já é base64 ou array, usar função de extração
                $qrCode = $this->extractQrCodeString($qrCode);
            }
        }

        // Se não tiver instanceId mas tiver agentId, buscar instância pelo agentId
        if (!$instanceId && $agentId) {
            $instance = WhatsappInstance::where('agent_id', $agentId)
                ->where('user_id', $this->user->uuid)
                ->first();

            if ($instance) {
                $instanceId = $instance->uuid;
            }
        }

        if (!$instanceId) {
            return;
        }

        // Buscar instância se ainda não foi encontrada
        if (!isset($instance)) {
            $instance = WhatsappInstance::where('uuid', $instanceId)
                ->where('user_id', $this->user->uuid)
                ->first();
        }

        if (!$instance) {
            return;
        }

        // Log apenas mudanças significativas de estado (agora com variáveis definidas)
        $currentStatus = $instance->status ?? 'unknown';
        $statusMap = [
            'ready' => 'connected',
            'qr' => 'connecting',
            'authenticated' => 'connecting',
            'connecting' => 'connecting',
            'disconnected' => 'disconnected',
            'close' => 'disconnected',
            'error' => 'error'
        ];
        $newStatus = $statusMap[$status] ?? $status;

        if ($currentStatus !== $newStatus) {
            Log::info('Status alterado', [
                'agent_id' => $agentId,
                'from' => $currentStatus,
                'to' => $newStatus,
                'has_qr' => isset($data['qr_code']) || isset($data['qrCode'])
            ]);
        }

        try {
            // STATE MACHINE: Transições claras e determinísticas
            $this->transitionState($instance, $status, $data, $qrCode, $phoneNumber);

            // ✅ Apenas atualiza last_activity — error_message é responsabilidade do transitionState
            $instance->update([
                'last_activity' => now()
            ]);

            $this->loadInstances();
        } catch (\Exception $e) {
            Log::error('Erro no instanceStatusUpdated', [
                'instance_id' => $instanceId,
                'agent_id' => $agentId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function checkInstanceStatus($data = null)
    {
        if (!$data) {
            // Dados não fornecidos - pode ser chamada automática durante inicialização
            // Não logar warning para evitar poluição de logs
            return;
        }

        $instanceId = $data['instanceId'] ?? null;
        $userId = $this->user->uuid;

        if (!$instanceId) {
            // Dados incompletos - pode ser chamada automática sem contexto
            // Não logar warning para evitar poluição de logs
            return;
        }

        // Verificar se a instância pertence ao usuário
        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $userId)
            ->first();

        if (!$instance) {
            Log::warning('checkInstanceStatus: Instância não encontrada ou não pertence ao usuário', [
                'instanceId' => $instanceId,
                'userId' => $userId
            ]);
            return;
        }

        if ($instance->provider === 'zapi') {
            $connected = app(WhatsappProviderService::class)->isConnected($instance);
            $instance->refresh();

            if ($connected) {
                $this->resetModalState();
            } else {
                $this->selectedInstance = $instance->toArray();
            }

            $this->loadInstances();
            return;
        }

        // Verificar status via WebSocket (o Node identifica a instância pelo agent_id = agents.uuid)
        $this->dispatch('check-instance-status', [
            'agentId' => $instance->agent_id,
            'userId' => $userId
        ]);

        Log::info('checkInstanceStatus: Solicitação de verificação enviada', [
            'instanceId' => $instanceId,
            'agentId' => $instance->instance_id,
            'userId' => $userId
        ]);
    }

    public function showDeleteConfirm($instanceId, $instanceName)
    {
        $this->dispatch('showDeleteInstanceConfirm', [
            'id' => $instanceId,
            'name' => $instanceName
        ]);
    }

    public function deleteInstance($instanceId = null)
    {
        if (!$instanceId) {
            $this->dispatch('error', 'ID da instância não fornecido.');
            return;
        }

        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $this->user->uuid)
            ->first();

        if (!$instance) {
            $this->dispatch('error', 'Instância não encontrada.');
            return;
        }

        // Verificar se é uma instância de teste expirada (não pode ser deletada)
        if ($instance->is_trial && $instance->isTrialExpired()) {
            $this->dispatch('error', 'Instâncias de teste expiradas não podem ser deletadas. Assine um plano para funcionalidades completas.');
            return;
        }

        try {
            // Primeiro desconecta se estiver conectada via Socket.io
            if ($instance->provider === 'zapi') {
                app(WhatsappProviderService::class)->disconnect($instance);
            } elseif ($instance->isConnected() || $instance->isConnecting()) {
                $this->dispatch('disconnectViaSocket', [
                    'instanceId' => $instance->uuid,
                    'agentId' => $instance->agent_id
                ]);
            }

            $instance->delete();
            $this->loadInstances();
            $this->dispatch('instanceDeleted', 'Instância excluída com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao excluir instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            $this->dispatch('error', 'Erro ao excluir instância: ' . $e->getMessage());
        }
    }

    public function refreshZapiQrCode($instanceId): void
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)
            ->where('user_id', $this->user->uuid)
            ->first();

        if (!$instance || $instance->provider !== 'zapi') {
            return;
        }

        $result = app(WhatsappProviderService::class)->getQrCode($instance);
        if (!empty($result['error'])) {
            $this->dispatch('error', $result['message'] ?? 'Não foi possível atualizar o QR Code.');
            return;
        }

        $qr = $this->extractQrCodeString($result['qr'] ?? null);
        $instance->update([
            'status' => 'connecting',
            'qr_code' => $qr,
            'error_message' => null,
            'last_activity' => now(),
        ]);
        $this->setQrCodeData($result['qr'] ?? null);
        $this->setPersistentQrCode($result['qr'] ?? null);
        $this->selectedInstance = $instance->fresh()->toArray();
        $this->loadInstances();
        $this->dispatch('qrCodeReceived');
    }

    private function resetForm()
    {
        $this->instanceMode = 'agent';
        $this->agentType = 'existing';
        $this->agent_id = '';
        $this->selectedModel = '';
        $this->name = '';
        $this->description = '';
        $this->webhook_url = '';
        $this->webhook_enabled = false;
    }
}
