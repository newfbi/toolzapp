<?php

namespace App\Http\Livewire\Agents;

use App\Models\Agents;
use App\Models\Chats;
use App\Models\Messages;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\WhatsappProviderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class ManageAgentController extends Component
{
    public $user;
    public $userId;
    public $agentId;
    public $isPersonal;
    public $agent;
    public $whatsappInstance;
    public $chats = [];
    public $selectedChat = null;
    public $messages = [];
    public $agentMessage;

    // WhatsApp Instance State
    public $showQrModal = false;
    public $qrCodeData = null;
    public $connectingInstance = null;
    public $persistentQrCode = null;

    protected $listeners = [
        'instanceStatusUpdated'
    ];

    public function mount($agentId)
    {
        /** @var \App\Models\User $user */
        $this->user = User::find(Auth::id());
        $this->userId = $this->user->uuid;
        view()->share('title', 'Gerenciar Chat');

        // ✅ Administradores podem ver qualquer agente
        $isAdmin = $this->user->hasAnyPermission(['administrador']);

        $query = Agents::with([
            'chats' => fn ($chatQuery) => $chatQuery->withCount('messages'),
            'whatsappInstance',
        ])
            ->where('uuid', $agentId);

        // Se não for administrador, filtrar por user_id
        if (!$isAdmin) {
            $query->where('user_id', Auth::user()->uuid);
        }

        $agent = $query->first();

        if (!$agent) {
            $errorMessage = $isAdmin
                ? 'Agente não encontrado no sistema.'
                : 'Agente não encontrado ou você não tem permissão para acessá-lo.';

            return redirect()->to('agentes')->with('error', $errorMessage);
        }

        $this->agent = $agent;
        $this->whatsappInstance = $agent->whatsappInstance;
        $this->chats = $this->agent->chats;
        $this->agentId = $agent->uuid;
        $this->isPersonal = $agent->is_personal;
        $this->selectedChat = collect($this->chats)->first();

        if ($this->selectedChat) {
            $this->messages = Messages::where(['agent_id' => $this->agentId, 'chat_id' => $this->selectedChat->uuid])
                ->orderBy('id', 'asc')
                ->get();
        }

        // Restaurar estado do QR code se necessário
        $this->restoreQrCodeState();
    }

    /**
     * Restaura o estado do QR code se necessário
     */
    private function restoreQrCodeState()
    {
        if ($this->whatsappInstance && $this->whatsappInstance->isConnecting() && $this->whatsappInstance->qr_code) {
            $this->qrCodeData = $this->whatsappInstance->qr_code;
            $this->persistentQrCode = $this->whatsappInstance->qr_code;
            $this->showQrModal = true;
            $this->connectingInstance = $this->whatsappInstance->uuid;
        }
    }

    public function render()
    {
        // Recarregar instância para pegar status atualizado
        if ($this->whatsappInstance) {
            $this->whatsappInstance->refresh();
        }

        return view('livewire.agents.manage');
    }

    // ========================================
    // MÉTODOS DE GERENCIAMENTO DE CHAT
    // ========================================
    public function selectChat($chatId)
    {
        $this->selectedChat = Chats::where('uuid', $chatId)
            ->where('agent_id', $this->agentId)
            ->where('user_id', $this->userId)
            ->withCount('messages')
            ->first();

        if (!$this->selectedChat) {
            session()->flash('error', 'Chat não encontrado ou sem permissão de acesso.');
            return;
        }

        $this->messages = Messages::where('chat_id', $chatId)->orderBy('id')->get();
        $this->dispatch('chatChanged');
    }

    public function pauseAgent($chatId)
    {
        $chat = Chats::where('uuid', $chatId)
            ->where('agent_id', $this->agentId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$chat) {
            session()->flash('error', 'Chat não encontrado ou sem permissão de acesso.');
            return;
        }

        $chat->is_pause = $chat->is_pause == 'true' ? 'false' : 'true';
        $chat->save();
        $this->selectedChat = $chat;
    }

    public function sendMessage()
    {
        if ($this->agentMessage == "") return;

        // Validar status do agente
        if ($this->agent->status !== 'enabled') {
            session()->flash('error', 'O agente está desabilitado. Ative o agente para enviar mensagens.');
            return;
        }

        $chat = Chats::where('uuid', $this->selectedChat->uuid)
            ->where('agent_id', $this->agentId)
            ->where('user_id', $this->userId)
            ->first();
        if (!$chat) {
            session()->flash('error', 'Chat não encontrado, verifique e tente novamente!');
            return;
        }

        if ($chat->is_pause == 'false') {
            session()->flash('error', 'Pause o Agente, antes de intervir no chat!');
            return;
        }

        if (!$this->whatsappInstance) {
            session()->flash('error', 'Instância WhatsApp não está conectada.');
            return;
        }

        if ($this->whatsappInstance->provider === 'zapi') {
            $result = app(WhatsappProviderService::class)->sendText(
                $this->whatsappInstance,
                (string) $this->selectedChat->phone,
                $this->agentMessage
            );

            if (($result['success'] ?? false) !== true) {
                session()->flash('error', 'Falha ao enviar pela Z-API: ' . ($result['error'] ?? 'erro desconhecido'));
                return;
            }

            Messages::create([
                'chat_id' => $chat->uuid,
                'agent_id' => $this->agentId,
                'name' => 'agente',
                'content' => $this->agentMessage,
                'direction' => 'output',
                'phone' => $chat->phone,
                'whatsapp_message_id' => $result['message_id'] ?? null,
                'delivery_status' => $result['delivery_status'] ?? 'accepted',
            ]);
            $chat->update([
                'last_message' => $this->agentMessage,
                'time' => now()->format('H:i'),
            ]);
            $this->messages = Messages::where('chat_id', $chat->uuid)->get();
            $this->dispatch('clearInput');
            $this->reset('agentMessage');
            session()->flash('message', 'Mensagem enviada pela Z-API.');
            return;
        }

        if (!$this->whatsappInstance->isConnected()) {
            session()->flash('error', 'Instância WhatsApp não está conectada.');
            return;
        }

        $recipient = $chat->contact_lid && str_contains($chat->contact_lid, '@lid')
            ? $chat->contact_lid
            : $chat->phone;

        if (!$recipient) {
            session()->flash('error', 'O chat não possui um destinatário válido.');
            return;
        }

        try {
            // Dispara evento para JavaScript enviar via Socket.io
            $this->dispatch('sendMessageViaSocket', [
                'instanceId' => $this->whatsappInstance->uuid,
                'agentId' => $this->agentId,
                'phone' => $chat->phone,
                'to' => $recipient,
                'recipient_type' => str_contains($recipient, '@lid') ? 'lid' : 'phone',
                'message' => $this->agentMessage
            ]);

            Messages::create([
                'chat_id' => $chat->uuid,
                'agent_id' => $this->agentId,
                'name' => 'agente',
                'content' => $this->agentMessage,
                'direction' => 'output',
                'phone' => $chat->phone
            ]);

            $chat->update([
                'last_message' => $this->agentMessage,
                'time' => now()->format('H:i'),
            ]);

            $this->messages = Messages::where('chat_id', $chat->uuid)->get();
            $this->dispatch('clearInput');
            $this->reset('agentMessage');

            session()->flash('message', 'Mensagem enviada com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem', [
                'error' => $e->getMessage(),
                'agent_id' => $this->agentId
            ]);
            session()->flash('error', 'Erro ao enviar mensagem: ' . $e->getMessage());
        }
    }

    #[On('messageReceived')]
    public function handleIncomingMessage($payload)
    {
        $from = $payload['from'] ?? '';
        $contactLid = $payload['contact_lid'] ?? null;
        $cleanFrom = preg_replace('/[^\d]/', '', $from);
        $chat = collect($this->chats)->first(function ($candidate) use ($from, $contactLid, $cleanFrom) {
            if ($contactLid && $candidate->contact_lid === $contactLid) {
                return true;
            }

            $candidatePhone = $candidate->phone ?? '';
            $candidateDigits = preg_replace('/[^\d]/', '', $candidatePhone);

            return $candidatePhone === $from
                || ($cleanFrom && $candidateDigits && str_contains($candidateDigits, $cleanFrom));
        });

        if (!$chat) {
            return;
        }

        if ($this->selectedChat && $this->selectedChat->uuid === $chat->uuid) {
            $this->selectedChat = $chat;
            $this->messages = Messages::where('chat_id', $chat->uuid)->get();
            $this->agent->refresh();
        }
        $this->dispatch('chatChanged');
    }

    // ========================================
    // MÉTODOS DE CONEXÃO WHATSAPP
    // ========================================

    /**
     * Conecta a instância WhatsApp
     */
    public function connectInstance()
    {
        if (!$this->whatsappInstance) {
            session()->flash('error', 'Nenhuma instância WhatsApp configurada para este agente.');
            return;
        }

        if ($this->whatsappInstance->isConnected()) {
            session()->flash('message', 'Instância já está conectada.');
            return;
        }

        try {
            // Reseta estado do modal
            $this->resetModalState();

            $this->whatsappInstance->updateStatus('connecting');
            $this->whatsappInstance->update(['qr_code' => null, 'error_message' => null]);

            // Prepara dados para conexão via Socket.io
            $this->showQrModal = true;
            $this->connectingInstance = $this->whatsappInstance->uuid;

            // Restaurar QR code se existir
            if ($this->whatsappInstance->qr_code) {
                $this->qrCodeData = $this->whatsappInstance->qr_code;
                $this->persistentQrCode = $this->whatsappInstance->qr_code;
            } else {
                $this->qrCodeData = null;
            }

            // Dispara evento para JavaScript conectar via Socket.io
            $this->dispatch('connectViaSocket', [
                'instanceId' => $this->whatsappInstance->uuid,
                'agentId' => $this->agentId,
                'userId' => $this->user->uuid
            ]);

            session()->flash('message', 'Conectando instância WhatsApp...');
        } catch (\Exception $e) {
            Log::error('Erro ao conectar instância WhatsApp', [
                'error' => $e->getMessage(),
                'agent_id' => $this->agentId
            ]);
            $this->whatsappInstance->updateStatus('error', $e->getMessage());
            $this->resetModalState();
            session()->flash('error', 'Erro ao conectar instância: ' . $e->getMessage());
        }
    }

    /**
     * Desconecta a instância WhatsApp
     */
    public function disconnectInstance()
    {
        if (!$this->whatsappInstance) {
            session()->flash('error', 'Nenhuma instância WhatsApp configurada.');
            return;
        }

        try {
            // Dispara evento para JavaScript desconectar via Socket.io
            $this->dispatch('disconnectViaSocket', [
                'instanceId' => $this->whatsappInstance->uuid,
                'agentId' => $this->agentId
            ]);

            $this->whatsappInstance->updateStatus('disconnected');
            $this->whatsappInstance->update([
                'phone_number' => null,
                'connected_at' => null,
                'qr_code' => null
            ]);

            $this->resetModalState();
            session()->flash('message', 'Instância desconectada com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao desconectar instância WhatsApp', [
                'error' => $e->getMessage(),
                'agent_id' => $this->agentId
            ]);
            session()->flash('error', 'Erro ao desconectar instância: ' . $e->getMessage());
        }
    }

    /**
     * Reinicia a instância WhatsApp
     */
    public function restartInstance()
    {
        if (!$this->whatsappInstance) {
            session()->flash('error', 'Nenhuma instância WhatsApp configurada.');
            return;
        }

        try {
            // ✅ Apenas dispara evento de restart (SEM desconectar antes)
            // O servidor vai desconectar temporariamente e reconectar mantendo a sessão
            $this->dispatch('restartViaSocket', [
                'instanceId' => $this->whatsappInstance->uuid,
                'agentId' => $this->agentId,
                'userId' => $this->user->uuid
            ]);

            session()->flash('message', 'Instância sendo reiniciada...');
        } catch (\Exception $e) {
            Log::error('Erro ao reiniciar instância WhatsApp', [
                'error' => $e->getMessage(),
                'agent_id' => $this->agentId
            ]);
            session()->flash('error', 'Erro ao reiniciar instância: ' . $e->getMessage());
        }
    }

    /**
     * Fecha o modal de QR Code
     */
    public function closeQrModal()
    {
        // Se estiver conectando, reseta o status para disconnected
        if ($this->connectingInstance && $this->whatsappInstance) {
            if ($this->whatsappInstance->status === 'connecting' && !$this->whatsappInstance->phone_number) {
                $this->whatsappInstance->updateStatus('disconnected');
                $this->whatsappInstance->update([
                    'qr_code' => null,
                    'error_message' => 'Conexão cancelada pelo usuário.'
                ]);
            }
        }

        $this->resetModalState();
        $this->dispatch('modalClosed');
    }

    /**
     * Reseta o estado do modal
     */
    private function resetModalState()
    {
        $this->showQrModal = false;
        $this->connectingInstance = null;
        $this->qrCodeData = null;
        $this->persistentQrCode = null;
    }

    /**
     * Atualiza status da instância via evento do Socket.io
     */
    public function instanceStatusUpdated($data)
    {
        if (empty($data) || !$this->whatsappInstance) {
            return;
        }

        $instanceId = $data['instanceId'] ?? $data['instance_id'] ?? null;
        $agentId = $data['agent_id'] ?? $data['agentId'] ?? null;
        $status = $data['status'] ?? null;
        $phoneNumber = $data['phone_number'] ?? $data['phoneNumber'] ?? null;
        $qrCode = $data['qr_code'] ?? $data['qrCode'] ?? null;

        // Verifica se o evento é para este agente
        if ($agentId !== $this->agentId && $instanceId !== $this->whatsappInstance->uuid) {
            return;
        }

        try {
            $this->transitionState($status, $data, $qrCode, $phoneNumber);

            $this->whatsappInstance->update([
                'last_activity' => now(),
            ]);

            // Recarrega chats se conectou
            if ($status === 'ready' || $status === 'connected') {
                $this->chats = $this->agent->chats()->withCount('messages')->get();
                $this->selectedChat = collect($this->chats)->first();

                if ($this->selectedChat) {
                    $this->messages = Messages::where([
                        'agent_id' => $this->agentId,
                        'chat_id' => $this->selectedChat->uuid
                    ])->orderBy('id', 'asc')->get();
                }
            }
        } catch (\Exception $e) {
            Log::error('Erro no instanceStatusUpdated', [
                'agent_id' => $this->agentId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * STATE MACHINE: Gerencia transições de estado
     */
    private function transitionState($baileysStatus, $payload, $qrCode, $phoneNumber)
    {
        $updateData = [];

        switch ($baileysStatus) {
            case 'qr':
                // QR Code gerado - manter modal aberto
                $updateData['status'] = 'connecting';
                $updateData['qr_code'] = $qrCode;
                $updateData['error_message'] = null;

                $this->qrCodeData = $qrCode;
                $this->persistentQrCode = $qrCode;
                $this->showQrModal = true;
                $this->connectingInstance = $this->whatsappInstance->uuid;
                break;

            case 'authenticated':
                // QR lido - manter modal aberto com feedback
                $updateData['status'] = 'connecting';
                $updateData['phone_number'] = $phoneNumber;
                $updateData['error_message'] = null;
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
                session()->flash('message', 'WhatsApp conectado com sucesso!');
                break;

            case 'disconnected':
            case 'close':
                // Desconexão
                $updateData['status'] = 'disconnected';
                $updateData['qr_code'] = null;
                $updateData['error_message'] = $payload['message'] ?? 'Conexão perdida';

                if ($this->connectingInstance !== $this->whatsappInstance->uuid) {
                    $this->resetModalState();
                }
                break;

            case 'error':
                // Erro - fechar modal e limpar estado
                $updateData['status'] = 'error';
                $updateData['qr_code'] = null;
                $updateData['error_message'] = $payload['error'] ?? $payload['message'] ?? 'Erro desconhecido';

                $this->resetModalState();
                Log::error('Erro na conexão WhatsApp', [
                    'agent_id' => $this->agentId,
                    'error' => $payload['error'] ?? $payload['message']
                ]);
                break;

            default:
                return;
        }

        $this->whatsappInstance->update($updateData);
    }
}
