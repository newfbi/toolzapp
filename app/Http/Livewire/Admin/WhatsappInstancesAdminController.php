<?php

namespace App\Http\Livewire\Admin;

use App\Models\WhatsappInstance;
use App\Models\User;
use App\Services\WhatsappProviderService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class WhatsappInstancesAdminController extends Component
{
    use WithPagination;

    public $filterSearch = '';
    public $filterStatus = '';
    public $filterUser = '';
    public $configInstanceId = null;
    public $activeProvider = 'baileys';
    public $zapiInstanceId = '';
    public $zapiInstanceToken = '';
    public $zapiClientToken = '';
    public $configureZapiWebhooks = true;

    protected $listeners = [
        'instanceStatusUpdated' => 'refreshInstances',
        'deleteInstance' => 'deleteInstance',
    ];

    public function mount()
    {
        view()->share('title', 'Gerenciar Instâncias WhatsApp');
    }

    public function render()
    {
        $query = WhatsappInstance::with(['user', 'agent'])
            ->orderBy('status', 'desc')
            ->orderBy('updated_at', 'desc');

        // Filtro de busca
        if ($this->filterSearch) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->filterSearch . '%')
                    ->orWhere('instance_id', 'like', '%' . $this->filterSearch . '%')
                    ->orWhere('phone_number', 'like', '%' . $this->filterSearch . '%')
                    ->orWhereHas('user', function ($userQuery) {
                        $userQuery->where('name', 'like', '%' . $this->filterSearch . '%')
                            ->orWhere('email', 'like', '%' . $this->filterSearch . '%');
                    })
                    ->orWhereHas('agent', function ($agentQuery) {
                        $agentQuery->where('name', 'like', '%' . $this->filterSearch . '%');
                    });
            });
        }

        // Filtro de status
        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        // Filtro de usuário
        if ($this->filterUser) {
            $query->where('user_id', $this->filterUser);
        }

        $instances = $query->paginate(15);
        $users = User::orderBy('name')->get();

        return view('livewire.admin.whatsapp.index', [
            'instances' => $instances,
            'users' => $users
        ]);
    }

    public function refreshInstances()
    {
        // Força atualização da lista
        $this->render();
    }

    public function restartInstance($instanceId)
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)->first();

        if (!$instance) {
            session()->flash('error', 'Instância não encontrada.');
            return;
        }

        try {
            if ((string) $instance->provider === 'zapi') {
                $result = app(WhatsappProviderService::class)->restart($instance);
                if (!empty($result['error'])) {
                    throw new \RuntimeException($result['message'] ?? 'Falha ao reiniciar a Z-API.');
                }
                $instance->update(['status' => 'connecting', 'last_activity' => now()]);
                session()->flash('message', 'Instância Z-API reiniciada.');
                return;
            }

            // Dispara evento para JavaScript reiniciar via Socket.io
            $this->dispatch('restartViaSocket', [
                'instanceId' => $instance->uuid,
                'agentId' => $instance->agent_id,
                'userId' => $instance->user_id
            ]);

            session()->flash('message', 'Instância sendo reiniciada...');
        } catch (\Exception $e) {
            Log::error('Erro ao reiniciar instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            session()->flash('error', 'Erro ao reiniciar instância: ' . $e->getMessage());
        }
    }

    public function disconnectInstance($instanceId)
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)->first();

        if (!$instance) {
            session()->flash('error', 'Instância não encontrada.');
            return;
        }

        try {
            if ((string) $instance->provider === 'zapi') {
                $result = app(WhatsappProviderService::class)->disconnect($instance);
                if (!empty($result['error'])) {
                    throw new \RuntimeException($result['message'] ?? 'Falha ao desconectar a Z-API.');
                }
                session()->flash('message', 'Instância Z-API desconectada com sucesso!');
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
    }

    public function showDeleteConfirm($instanceId, $instanceName)
    {
        $this->dispatch('showDeleteInstanceConfirm', [
            'id' => $instanceId,
            'name' => $instanceName
        ]);
    }

    public function openProviderConfig($instanceId): void
    {
        $instance = WhatsappInstance::where('uuid', $instanceId)->first();
        if (!$instance) {
            $this->dispatch('error', 'Instância não encontrada.');
            return;
        }

        $settings = $instance->providerSettings();
        $this->configInstanceId = $instance->uuid;
        $this->activeProvider = $instance->provider === 'zapi' ? 'zapi' : 'baileys';
        $this->zapiInstanceId = (string) ($settings['zapi_instance_id'] ?? ($this->activeProvider === 'zapi' ? $instance->instance_id : ''));
        $this->zapiInstanceToken = '';
        $this->zapiClientToken = '';
        $this->configureZapiWebhooks = true;
        $this->dispatch('openWhatsappProviderConfig');
    }

    public function saveProviderConfig(): void
    {
        $this->validate([
            'configInstanceId' => 'required|string',
            'activeProvider' => 'required|in:baileys,zapi',
            'zapiInstanceId' => 'nullable|string|max:191',
            'zapiInstanceToken' => 'nullable|string|max:191',
            'zapiClientToken' => 'nullable|string|max:255',
        ]);

        $instance = WhatsappInstance::where('uuid', $this->configInstanceId)->first();
        if (!$instance) {
            $this->dispatch('error', 'Instância não encontrada.');
            return;
        }

        try {
            $settings = $instance->providerSettings();
            $oldProvider = (string) $instance->provider;

            if ($oldProvider !== 'zapi' && !empty($instance->instance_id)) {
                $settings['local_instance_id'] = $settings['local_instance_id'] ?? $instance->instance_id;
            }

            if ($this->activeProvider === 'zapi') {
                $zapiId = trim((string) $this->zapiInstanceId);
                $zapiToken = trim((string) $this->zapiInstanceToken);
                $savedToken = (string) ($settings['zapi_instance_token'] ?? ($oldProvider === 'zapi' ? $instance->instance_token : ''));
                $zapiToken = $zapiToken !== '' ? $zapiToken : $savedToken;

                if ($zapiId === '' || $zapiToken === '') {
                    throw new \InvalidArgumentException('Informe o ID e o token da instância Z-API.');
                }

                if ($oldProvider !== 'zapi') {
                    $localDisconnected = app(WhatsappProviderService::class)->disconnectLocal($instance);
                    if (!$localDisconnected) {
                        throw new \RuntimeException(
                            'Não foi possível confirmar o encerramento da sessão local antes de ativar a Z-API.'
                        );
                    }
                }

                $settings['zapi_instance_id'] = $zapiId;
                $settings['zapi_instance_token'] = $zapiToken;
                if (trim((string) $this->zapiClientToken) !== '') {
                    $settings['zapi_client_token'] = trim((string) $this->zapiClientToken);
                }

                $instance->update([
                    'provider' => 'zapi',
                    'instance_id' => $zapiId,
                    'instance_token' => $zapiToken,
                    'settings' => $settings,
                    'status' => 'disconnected',
                    'phone_number' => null,
                    'connected_at' => null,
                    'error_message' => null,
                    'last_activity' => now(),
                ]);

                $provider = app(WhatsappProviderService::class);
                $provider->syncZapiStatus($instance);

                if ($this->configureZapiWebhooks) {
                    $webhooks = $provider->configureWebhooks($instance);
                    if (!empty($webhooks['result']['error'])) {
                        Log::warning('Z-API configurada sem webhook automático', [
                            'instance_id' => $instance->uuid,
                            'message' => $webhooks['result']['message'] ?? null,
                        ]);
                    }
                }

                session()->flash('message', 'Z-API definida como provedora ativa para esta instância.');
            } else {
                $localId = (string) ($settings['local_instance_id'] ?? '');
                if ($localId === '') {
                    $localId = 'baileys_' . substr((string) $instance->agent_id, 0, 8);
                }

                if ($oldProvider === 'zapi') {
                    $zapiDisconnected = app(WhatsappProviderService::class)->disconnect($instance);
                    if (!empty($zapiDisconnected['error'])) {
                        throw new \RuntimeException(
                            $zapiDisconnected['message'] ?? 'Não foi possível encerrar a sessão Z-API antes de ativar o Baileys.'
                        );
                    }
                }

                $instance->update([
                    'provider' => 'baileys',
                    'instance_id' => $localId,
                    'instance_token' => null,
                    'settings' => $settings,
                    'status' => 'disconnected',
                    'phone_number' => null,
                    'connected_at' => null,
                    'qr_code' => null,
                    'error_message' => null,
                    'last_activity' => now(),
                ]);

                session()->flash('message', 'Baileys definido como provedor ativo. A sessão local foi preservada.');
            }

            $this->resetProviderConfig();
            $this->dispatch('providerConfigSaved');
        } catch (\Throwable $e) {
            Log::error('Erro ao salvar provedor da instância WhatsApp', [
                'instance_id' => $this->configInstanceId,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function resetProviderConfig(): void
    {
        $this->reset([
            'configInstanceId',
            'zapiInstanceId',
            'zapiInstanceToken',
            'zapiClientToken',
        ]);
        $this->activeProvider = 'baileys';
        $this->configureZapiWebhooks = true;
    }

    public function deleteInstance($instanceId)
    {
        if (!$instanceId) {
            $this->dispatch('error', 'ID da instância não fornecido.');
            return;
        }

        $instance = WhatsappInstance::where('uuid', $instanceId)->first();

        if (!$instance) {
            $this->dispatch('error', 'Instância não encontrada.');
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
            $this->dispatch('instanceDeleted', 'Instância excluída com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao excluir instância WhatsApp', [
                'error' => $e->getMessage(),
                'instance_id' => $instanceId
            ]);
            $this->dispatch('error', 'Erro ao excluir instância: ' . $e->getMessage());
        }
    }

    public function updatingFilterSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterStatus()
    {
        $this->resetPage();
    }

    public function updatingFilterUser()
    {
        $this->resetPage();
    }
}
