<?php

namespace App\Http\Livewire\Chats;

use App\Models\Chats;
use App\Models\Messages;
use App\Models\Agents;
use App\Models\WhatsappInstance;
use App\Services\WhatsappProviderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatsController extends Component
{
    public $user;
    public $userId;
    public $agentId;
    public $agent;
    public $chats = []; // Lista de chats
    public $selectedChat = null; // Chat selecionado
    public $messages = []; // Mensagens do chat selecionado
    public $agentMessage;
    public $search = '';
    public $selectedAgentFilter = '';
    public $statusFilter = 'all';
    public $availableAgents = [];

    public function updatedSearch()
    {
        $this->loadChats();
    }

    public function updatedSelectedAgentFilter()
    {
        $this->loadChats();
    }

    public function updatedStatusFilter()
    {
        $this->loadChats();
    }

    /**
     * Carrega lista de chats do usuário e de seus agentes (incluindo disparos)
     */
    public function loadChats()
    {
        $query = Chats::where(function ($q) {
            $q->where('user_id', $this->userId)
              ->orWhereHas('agent', function ($agentQuery) {
                  $agentQuery->where('user_id', $this->userId);
              });
        })
        ->with('agent')
        ->withCount('messages')
        ->whereNotNull('phone');

        if (!empty(trim($this->search))) {
            $search = trim($this->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('last_message', 'like', "%{$search}%");
            });
        }

        if (!empty($this->selectedAgentFilter)) {
            $query->where('agent_id', $this->selectedAgentFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_pause', 'false');
        } elseif ($this->statusFilter === 'paused') {
            $query->where('is_pause', 'true');
        }

        $this->chats = $query->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function mount()
    {
        $this->user = Auth::user();
        $this->userId = $this->user->uuid;
        view()->share('title', 'Gerenciar Chats');

        $this->availableAgents = Agents::where('user_id', $this->userId)->get();

        // Carregar chats filtrados (apenas telefones)
        $this->loadChats();

        $this->selectedChat = collect($this->chats)->first();
        if (!empty($this->selectedChat)) {
            $this->agentId = $this->selectedChat->agent_id;
            $this->agent = Agents::where('uuid', $this->agentId)->first();
            if ($this->selectedChat) {
                $this->messages = Messages::where(['chat_id' => $this->selectedChat->uuid])->orderBy('id', 'asc')->get();
            }
        }
    }

    public function render()
    {
        return view('livewire.chats.index');
    }

    public function selectChat($chatId)
    {
        $this->selectedChat = Chats::where('uuid', $chatId)
            ->where(function ($q) {
                $q->where('user_id', $this->userId)
                    ->orWhereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', $this->userId));
            })
            ->with('agent')
            ->withCount('messages')
            ->first();

        if (!$this->selectedChat) {
            session()->flash('error', 'Chat não encontrado ou sem permissão de acesso.');
            return;
        }

        $this->agentId = $this->selectedChat->agent_id;
        $this->agent = Agents::where('uuid', $this->agentId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$this->agent) {
            session()->flash('error', 'Agente não encontrado ou sem permissão de acesso.');
            return;
        }

        $this->messages = Messages::where('chat_id', $chatId)->orderBy('id', 'asc')->get();
        $this->dispatch('chatChanged', [
            'agentId' => $this->agentId,
            'chatId' => $chatId
        ]);
    }

    public function pauseAgent($chatId)
    {
        $chat = Chats::where('uuid', $chatId)
            ->where(function ($q) {
                $q->where('user_id', $this->userId)
                    ->orWhereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', $this->userId));
            })
            ->first();

        if (!$chat) {
            session()->flash('error', 'Chat não encontrado ou sem permissão de acesso.');
            return;
        }

        $oldStatus = $chat->is_pause;
        $chat->is_pause = $chat->is_pause == 'true' ? 'false' : 'true';
        $chat->save();
        $chat->refresh();
        $this->selectedChat = $chat;

        $statusMessage = $chat->is_pause == 'true' ? 'Pausado' : 'Ativado';
        session()->flash('message', "Agente {$statusMessage} com sucesso!");

        Log::info('Status do agente alterado:', [
            'chat_id' => $chatId,
            'old_status' => $oldStatus,
            'new_status' => $chat->is_pause
        ]);

        return;
    }

    public function getSelectedChatUuid(): ?string
    {
        if (empty($this->selectedChat)) {
            return null;
        }
        if (is_array($this->selectedChat)) {
            return $this->selectedChat['uuid'] ?? null;
        }
        return $this->selectedChat->uuid ?? null;
    }

    #[On('refreshChats')]
    public function syncRealtimeChats()
    {
        $this->loadChats();
        $selectedUuid = $this->getSelectedChatUuid();
        if ($selectedUuid) {
            $this->messages = Messages::where('chat_id', $selectedUuid)->orderBy('id', 'asc')->get();
        }
    }

    public function sendMessage()
    {
        if (empty(trim($this->agentMessage))) {
            return;
        }

        $selectedUuid = $this->getSelectedChatUuid();
        if (!$selectedUuid) {
            session()->flash('error', 'Nenhum chat selecionado!');
            return;
        }

        $chat = Chats::where('uuid', $selectedUuid)
            ->where(function ($q) {
                $q->where('user_id', $this->userId)
                    ->orWhereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', $this->userId));
            })
            ->first();
        if (!$chat) {
            session()->flash('error', 'Chat não encontrado, verifique e tente novamente!');
            return;
        }

        // Se o agente estiver ativo, pausa automaticamente para o atendente assumir o controle
        if ($chat->is_pause == 'false' || $chat->is_pause === false) {
            $chat->is_pause = 'true';
            $chat->save();
            $chat->refresh();
            $this->selectedChat = $chat;
        }

        // Busca o agente com a instância WhatsApp
        $agent = Agents::with('whatsappInstance')
            ->where('uuid', $chat->agent_id)
            ->where('user_id', $this->userId)
            ->first();
        if (!$agent) {
            session()->flash('error', 'Agente não encontrado!');
            return;
        }

        // Verifica se o agente tem instância WhatsApp configurada
        $whatsappInstance = $agent->whatsappInstance;
        if (!$whatsappInstance) {
            session()->flash('error', 'Agente não possui instância WhatsApp configurada!');
            return;
        }

        // Verifica o status da instância via banco de dados
        $instanceStatus = $this->checkInstanceStatusViaDatabase($agent->uuid);
        if ($instanceStatus['status'] !== 'success') {
            session()->flash('error', 'Erro ao verificar status da instância: ' . $instanceStatus['message']);
            return;
        }

        $status = $instanceStatus['data']['status'] ?? 'desconhecido';
        $phoneNumber = $instanceStatus['data']['phone_number'] ?? null;
        $connectedAt = $instanceStatus['data']['connected_at'] ?? null;
        $isConnected = $instanceStatus['data']['is_connected'] ?? false;
        $isConnecting = $instanceStatus['data']['is_connecting'] ?? false;
        $hasError = $instanceStatus['data']['has_error'] ?? false;
        $errorMessage = $instanceStatus['data']['error_message'] ?? null;
        $lastActivity = $instanceStatus['data']['last_activity'] ?? null;

        // 🎯 VERIFICAÇÃO INTELIGENTE: Se tem phone_number e connected_at, considera conectada mesmo se status diz "connecting"
        // Isso resolve casos onde o status no banco não foi atualizado mas a instância está realmente conectada
        $hasPhoneNumber = !empty($phoneNumber);
        $hasConnectedAt = !empty($connectedAt);
        $isActuallyConnected = $hasPhoneNumber && ($hasConnectedAt || $isConnected);

        // Se tem phone_number, considera conectada independente do status
        if ($hasPhoneNumber && !$isActuallyConnected) {
            Log::info('Instância tem phone_number mas status não atualizado, considerando conectada', [
                'agent_id' => $agent->uuid,
                'phone_number' => $phoneNumber,
                'db_status' => $status,
                'is_connected' => $isConnected
            ]);
            $isActuallyConnected = true;
        }

        // Se estiver conectando E não tem phone_number, verificar se não está travada
        if ($isConnecting && !$hasPhoneNumber) {
            // Verificar se está em "connecting" há muito tempo (mais de 5 minutos)
            $isStuck = false;
            if ($whatsappInstance && $whatsappInstance->updated_at) {
                $minutesSinceUpdate = now()->diffInMinutes($whatsappInstance->updated_at);
                $isStuck = $minutesSinceUpdate > 5;
            }

            if ($isStuck) {
                $errorMsg = 'Instância WhatsApp parece estar travada em "conectando". ';
                $errorMsg .= 'Acesse "Instâncias WhatsApp" e reconecte a instância deste agente.';
                session()->flash('error', $errorMsg);

                Log::warning('Instância travada em connecting', [
                    'agent_id' => $agent->uuid,
                    'instance_id' => $whatsappInstance->uuid ?? null,
                    'updated_at' => $whatsappInstance->updated_at ?? null
                ]);
                return;
            }

            // Sem phone_number e em connecting - realmente está conectando
            session()->flash('error', 'Instância WhatsApp está se conectando. Aguarde alguns instantes antes de enviar mensagens.');
            return;
        }

        // Se não estiver conectada (e não tem phone_number), mostrar mensagem detalhada
        if (!$isActuallyConnected && !$hasPhoneNumber) {
            $errorMsg = "Instância WhatsApp não está conectada. Status atual: {$status}";

            if ($hasError && $errorMessage) {
                $errorMsg .= "\nErro: {$errorMessage}";
            }

            // Sugestões baseadas no status
            if ($status === 'disconnected') {
                $errorMsg .= "\n\nSugestão: Acesse 'Instâncias WhatsApp' e conecte a instância deste agente.";
            } elseif ($status === 'error' || $status === 'corrupted') {
                $errorMsg .= "\n\nSugestão: A instância pode precisar ser reconectada. Acesse 'Instâncias WhatsApp' para mais detalhes.";
            }

            // Adicionar informações úteis para debug
            Log::warning('Tentativa de envio com instância desconectada', [
                'agent_id' => $agent->uuid,
                'instance_id' => $whatsappInstance->uuid ?? null,
                'instance_status' => $status,
                'is_connecting' => $isConnecting,
                'has_error' => $hasError,
                'error_message' => $errorMessage,
                'phone_number' => $phoneNumber,
                'has_phone_number' => $hasPhoneNumber
            ]);

            session()->flash('error', $errorMsg);
            return;
        }

        // ✅ Se chegou aqui, a instância está conectada (tem phone_number ou status = connected)
        // Pode prosseguir com o envio da mensagem

        // 🛡️ Validação: Verificar se o número de telefone é válido
        $recipientPhone = $chat->phone;
        Log::info('Enviando mensagem para chat', [
            'chat_id' => $chat->uuid,
            'phone' => $recipientPhone,
            'user_id' => $chat->user_id,
            'agent_id' => $chat->agent_id,
            'message_length' => strlen($this->agentMessage)
        ]);

        if (!$recipientPhone) {
            session()->flash('error', 'Número de telefone não encontrado no chat!');
            Log::error('Número de telefone não encontrado no chat', [
                'chat_id' => $chat->uuid,
                'chat_data' => $chat->toArray()
            ]);
            return;
        }

        // Limpar e validar formato do número
        $cleanPhone = preg_replace('/[^\d]/', '', $recipientPhone);
        if (strlen($cleanPhone) < 10) {
            session()->flash('error', "Número de telefone inválido: {$recipientPhone}. Use um número completo com DDD.");
            Log::error('Tentativa de envio para número inválido', [
                'chat_id' => $chat->uuid,
                'phone' => $recipientPhone,
                'clean_phone' => $cleanPhone,
                'clean_length' => strlen($cleanPhone),
                'user_id' => $this->userId,
                'chat_data' => $chat->toArray()
            ]);
            return;
        }

        // A instância Z-API não passa pelo Socket.IO/Baileys. Enviar aqui
        // mantém a decisão do provedor centralizada no registro da instância.
        if ($whatsappInstance->provider === 'zapi') {
            $result = app(WhatsappProviderService::class)->sendText(
                $whatsappInstance,
                $recipientPhone,
                $this->agentMessage
            );

            if (($result['success'] ?? false) !== true) {
                session()->flash('error', 'Falha ao enviar pela Z-API: ' . ($result['error'] ?? 'erro desconhecido'));
                return;
            }

            Messages::create([
                'uuid' => (string) Str::uuid(),
                'chat_id' => $chat->uuid,
                'agent_id' => $chat->agent_id,
                'name' => $agent->name,
                'content' => $this->agentMessage,
                'direction' => 'output',
                'phone' => $chat->phone,
                'whatsapp_message_id' => $result['message_id'] ?? null,
                'delivery_status' => $result['delivery_status'] ?? 'accepted',
                'credit_usage' => 0,
            ]);

            $chat->update([
                'last_message' => $this->agentMessage,
                'time' => now()->format('H:i'),
            ]);
            $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('id', 'asc')->get();
            $this->dispatch('clearInput');
            $this->reset('agentMessage');
            session()->flash('message', 'Mensagem enviada pela Z-API.');
            return;
        }

        // 🆕 DETERMINAR DESTINATÁRIO CORRETO PARA BAILEYS (LID preferencialmente)
        // O Baileys v7+ usa LID (@lid) como identificador primário
        $recipientIdentifier = $recipientPhone; // Fallback: número de telefone
        $recipientType = 'phone';
        $identifierSource = 'phone_fallback';

        // 🥇 Prioridade 1: LID armazenado no próprio chat
        if ($chat->contact_lid && str_contains($chat->contact_lid, '@lid')) {
            $recipientIdentifier = $chat->contact_lid;
            $recipientType = 'lid';
            $identifierSource = 'chat.contact_lid';
            Log::info('✅ Usando LID armazenado no chat para envio', [
                'chat_id' => $chat->uuid,
                'phone' => $recipientPhone,
                'lid' => $recipientIdentifier,
                'source' => $identifierSource
            ]);
        } else {
            Log::info('📞 Usando número de telefone para envio (LID não disponível)', [
                'chat_id' => $chat->uuid,
                'phone' => $recipientPhone,
                'agent_assistant_id' => $agent->assistant_id,
                'chat_contact_lid' => $chat->contact_lid,
                'source' => $identifierSource
            ]);
        }

        try {
            // Prepara payload para envio via websocket
            $payload = [
                'agentId' => $agent->uuid,
                'agent_id' => $agent->uuid,
                'userId' => $this->userId, // 🔐 MELHORIA: Adicionar userId para validação de segurança
                'user_id' => $this->userId,
                'phone' => $recipientPhone, // Número de telefone (sempre incluído para compatibilidade)
                'to' => $recipientIdentifier, // 🆕 LID preferencialmente, ou telefone como fallback
                'recipient_type' => $recipientType, // 🆕 Tipo de identificador: 'lid' ou 'phone'
                'from' => $chat->phone, // Remetente (número da instância ou instance_id)
                'message' => $this->agentMessage,
                'text' => $this->agentMessage
            ];

            // Salva a mensagem do agente no banco ANTES de enviar
            // 🔧 CORREÇÃO: Verificar duplicatas e usar campos corretos
            $existingMessage = Messages::where('chat_id', $chat->uuid)
                ->where('content', $this->agentMessage)
                ->where('direction', 'output') // Enviada pelo agente
                ->where('created_at', '>=', now()->subMinutes(5)) // Últimos 5 minutos
                ->first();

            if (!$existingMessage) {
                Messages::create([
                    'uuid' => (string) Str::uuid(),
                    'chat_id' => $chat->uuid,
                    'agent_id' => $chat->agent_id,
                    'name' => $agent->name, // Nome do agente
                    'content' => $this->agentMessage,
                    'direction' => 'output', // Enviada
                    'phone' => $chat->phone, // 🔧 CORREÇÃO: Número do contato, não user_id
                    'credit_usage' => 0
                ]);
            }

            // Atualiza a lista de mensagens
            $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('id', 'asc')->get();

            // Atualiza última mensagem do chat
            $chat->update([
                'last_message' => $this->agentMessage,
                'time' => now()->format('H:i')
            ]);

            // Envia via websocket
            $this->dispatch('send-wpp-message', $payload);

            // Limpa o campo de mensagem
            $this->dispatch('clearInput');
            $this->reset('agentMessage');

            session()->flash('message', 'Mensagem enviada com sucesso!');
        } catch (\Exception $e) {
            session()->flash('error', 'Erro ao enviar mensagem: ' . $e->getMessage());
            Log::error('Erro no envio via websocket no chat controller:', [
                'error' => $e->getMessage(),
                'chat_id' => $chat->uuid,
                'agent_id' => $agent->uuid
            ]);
        }
    }

    #[On('messageReceived')]
    public function handleIncomingMessage($payload = [])
    {
        if (is_array($payload) && isset($payload['payload'])) {
            $payload = $payload['payload'];
        }

        $from = $payload['from'] ?? '';
        $contactLid = $payload['contact_lid'] ?? null;
        $cleanFrom = preg_replace('/[^\d]/', '', $from);

        // Recarregar chats para garantir que novos chats e mensagens sejam incluídos
        $this->loadChats();

        $chat = collect($this->chats)->first(function ($c) use ($from, $contactLid, $cleanFrom) {
            if ($contactLid && !empty($c->contact_lid) && $c->contact_lid === $contactLid) {
                return true;
            }
            if ($c->phone === $from) {
                return true;
            }
            $chatDigits = preg_replace('/[^\d]/', '', $c->phone ?? '');
            if (!empty($cleanFrom) && !empty($chatDigits) && (str_contains($chatDigits, $cleanFrom) || str_contains($cleanFrom, $chatDigits))) {
                return true;
            }
            return false;
        });

        if ($chat) {
            // Atualizar informações do chat (última mensagem, horário)
            $chat->update([
                'last_message' => $payload['message'] ?? $payload['body'] ?? 'Nova mensagem',
                'time' => now()->format('H:i')
            ]);

            // Se for o chat selecionado atualmente, atualizar mensagens
            $selectedUuid = $this->getSelectedChatUuid();
            if ($selectedUuid && $selectedUuid === $chat->uuid) {
                $this->selectedChat = $chat;
                $this->messages = Messages::where('chat_id', $chat->uuid)->orderBy('id', 'asc')->get();
                if ($this->agent) {
                    $this->agent->refresh();
                }
                $this->dispatch('scrollDown');
            }

            // Recarregar lista de chats para refletir mudanças
            $this->loadChats();
            $this->dispatch('scrollDown');
        } else {
            Log::info('Chat não encontrado na lista atual para evento websocket, recarregando todos os chats', [
                'from' => $from,
                'contact_lid' => $contactLid
            ]);
            $this->loadChats();
        }
    }

    #[On('messageSentConfirmation')]
    public function handleMessageSentConfirmation($data)
    {

        // Recarregar mensagens se necessário
        if ($this->selectedChat) {
            $this->messages = Messages::where('chat_id', $this->selectedChat->uuid)->orderBy('id', 'asc')->get();

            // Atualizar informações do chat se foi uma mensagem enviada
            if (isset($data['to'])) {
                $this->selectedChat->update([
                    'last_message' => $data['message'] ?? 'Mensagem enviada',
                    'time' => now()->format('H:i')
                ]);
            }

            // Recarregar lista de chats para refletir mudanças
            $this->loadChats();
        }

        // Feedback visual adicional se necessário
        session()->flash('message', 'Mensagem confirmada como enviada!');
    }

    #[On('messageSendError')]
    public function handleMessageSendError($error)
    {
        Log::error('Erro no envio de mensagem via websocket:', $error);

        // Mostrar erro para o usuário
        session()->flash('error', 'Erro ao enviar mensagem: ' . ($error['error'] ?? 'Erro desconhecido'));

        // Recarregar mensagens para remover a mensagem que não foi enviada
        if ($this->selectedChat) {
            $this->messages = Messages::where('chat_id', $this->selectedChat->uuid)->orderBy('id', 'asc')->get();
        }
    }

    /**
     * Verifica o status da instância WhatsApp via WebSocket
     */
    public function checkInstanceStatusViaWebSocket($agentId = null)
    {
        $agentIdToCheck = $agentId ?: ($this->agentId ?? null);

        if (!$agentIdToCheck) {
            return ['status' => 'error', 'message' => 'Agent ID não fornecido'];
        }

        try {
            // Dispara evento para verificar status via WebSocket
            $this->dispatch('check-instance-status', [
                'agentId' => $agentIdToCheck
            ]);

            return ['status' => 'checking', 'message' => 'Verificando status...'];
        } catch (\Exception $e) {
            Log::error('Erro ao verificar status via WebSocket:', [
                'agent_id' => $agentIdToCheck,
                'error' => $e->getMessage()
            ]);

            return ['status' => 'error', 'message' => 'Erro ao verificar status: ' . $e->getMessage()];
        }
    }

    /**
     * Verifica o status da instância WhatsApp via banco de dados
     */
    public function checkInstanceStatusViaDatabase($agentId = null)
    {
        $agentIdToCheck = $agentId ?: ($this->agentId ?? null);

        if (!$agentIdToCheck) {
            return ['status' => 'error', 'message' => 'Agent ID não fornecido'];
        }

        try {
            // Busca a instância WhatsApp associada ao agente
            $instance = WhatsappInstance::where('agent_id', $agentIdToCheck)
                ->whereHas('agent', fn ($agentQuery) => $agentQuery->where('user_id', $this->userId))
                ->first();

            if (!$instance) {
                return [
                    'status' => 'not_found',
                    'message' => 'Instância WhatsApp não encontrada para este agente',
                    'data' => null
                ];
            }

            $providerConnected = null;
            if ($instance->provider === 'zapi') {
                $providerConnected = app(WhatsappProviderService::class)->isConnected($instance);
                $instance->refresh();
            }

            // Verifica o status atual
            $statusInfo = [
                'status' => $instance->status,
                'is_connected' => $providerConnected ?? $instance->isConnected(),
                'is_connecting' => $instance->isConnecting(),
                'has_error' => $instance->hasError(),
                'phone_number' => $instance->phone_number,
                'connected_at' => $instance->connected_at?->format('d/m/Y H:i:s'),
                'last_activity' => $instance->last_activity?->format('d/m/Y H:i:s'),
                'error_message' => $instance->error_message,
                'instance_id' => $instance->instance_id
            ];

            return [
                'status' => 'success',
                'message' => 'Status verificado com sucesso',
                'data' => $statusInfo
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao verificar status via banco de dados:', [
                'agent_id' => $agentIdToCheck,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro ao verificar status: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Método auxiliar para verificar se a instância está pronta para envio
     */
    public function isInstanceReadyForSending($agentId = null)
    {
        $statusCheck = $this->checkInstanceStatusViaDatabase($agentId);

        if ($statusCheck['status'] !== 'success') {
            return false;
        }

        $data = $statusCheck['data'];
        return $data['is_connected'] && !$data['has_error'];
    }

    /**
     * Handler para resposta de verificação de status via WebSocket
     */
    #[On('instanceStatusResponse')]
    public function handleInstanceStatusResponse($data)
    {

        // Atualizar status na interface se necessário
        if ($this->agent && $this->agent->uuid === $data['agent_id']) {
            // Disparar evento para atualizar a UI
            $this->dispatch('instanceStatusUpdated', $data);
        }
    }

    /**
     * Método público para verificar status da instância (pode ser chamado via JavaScript)
     * ✅ CORREÇÃO: Sempre usa banco de dados como fonte de verdade principal
     */
    public function checkInstanceStatus($agentId = null)
    {
        $agentIdToCheck = $agentId ?: ($this->agentId ?? null);

        if (!$agentIdToCheck) {
            session()->flash('error', 'Agent ID não fornecido para verificação de status');
            return;
        }

        // ✅ SEMPRE verificar banco de dados primeiro (fonte de verdade)
        $dbResult = $this->checkInstanceStatusViaDatabase($agentIdToCheck);

        // Se conseguiu verificar no banco, retornar esse resultado
        if ($dbResult['status'] === 'success') {
            // Disparar evento para atualizar UI com dados do banco
            $this->dispatch('instanceStatusUpdated', $dbResult['data']);
            return $dbResult;
        }

        // Apenas se não conseguir verificar no banco, tentar via WebSocket
        $wsResult = $this->checkInstanceStatusViaWebSocket($agentIdToCheck);
        return $wsResult;
    }

    /**
     * Método para obter informações detalhadas da instância atual
     */
    public function getCurrentInstanceInfo()
    {
        if (!$this->agentId) {
            return [
                'status' => 'error',
                'message' => 'Nenhum agente selecionado'
            ];
        }

        $status = $this->checkInstanceStatusViaDatabase($this->agentId);

        if ($status['status'] !== 'success') {
            return $status;
        }

        return [
            'status' => 'success',
            'data' => $status['data'],
            'is_ready' => $this->isInstanceReadyForSending($this->agentId),
            'recommendations' => $this->getInstanceRecommendations($status['data'])
        ];
    }

    /**
     * Método auxiliar para fornecer recomendações baseadas no status
     */
    private function getInstanceRecommendations($instanceData)
    {
        $recommendations = [];

        if (!$instanceData['is_connected']) {
            $recommendations[] = 'A instância não está conectada. Verifique se o QR Code foi lido.';
        }

        if ($instanceData['is_connecting']) {
            $recommendations[] = 'A instância está se conectando. Aguarde alguns instantes.';
        }

        if ($instanceData['has_error']) {
            $recommendations[] = 'Há um erro na instância. Verifique o log para mais detalhes.';
            if ($instanceData['error_message']) {
                $recommendations[] = 'Erro específico: ' . $instanceData['error_message'];
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Instância está pronta para uso.';
        }

        return $recommendations;
    }
}
