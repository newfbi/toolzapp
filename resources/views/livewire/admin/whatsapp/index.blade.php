<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Instâncias WhatsApp',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'WhatsApp',
            ],
        ],
    ])

    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>

        <div class="col-12">
            <div class="card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <h4 class="mb-0 card-title">Painel de Instâncias WhatsApp</h4>
                    <div class="d-flex align-items-center gap-2">
                        <!-- Filtro de Status -->
                        <select class="form-select" wire:model="filterStatus" style="width: 150px;">
                            <option value="">Todos Status</option>
                            <option value="connected">Conectado</option>
                            <option value="connecting">Conectando</option>
                            <option value="disconnected">Desconectado</option>
                            <option value="error">Erro</option>
                            <option value="corrupted">Corrompida</option>
                            <option value="banned">Banido</option>
                            <option value="expired">Expirada</option>
                        </select>

                        <!-- Filtro de Usuário -->
                        <select class="form-select" wire:model="filterUser" style="width: 200px;">
                            <option value="">Todos Usuários</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->uuid }}">{{ $user->name }}</option>
                            @endforeach
                        </select>

                        <!-- Busca -->
                        <div class="input-group" style="width: 300px;">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input type="text" class="form-control" placeholder="Nome, telefone, usuário..."
                                wire:model.debounce.300ms="filterSearch">
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" width="100%">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Provedor ativo</th>
                                    <th>Usuário</th>
                                    <th>Agente</th>
                                    <th>Telefone</th>
                                    <th>Status</th>
                                    <th>Conectado em</th>
                                    <th>Última Atividade</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($instances as $instance)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="ri-whatsapp-line text-success me-2 fs-5"></i>
                                                <div>
                                                    <strong>{{ $instance->name ?? 'Instância ' . $instance->instance_id }}</strong>
                                                    <br>
                                                    <small class="text-muted">{{ $instance->instance_id }}</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @if ($instance->provider === 'zapi')
                                                <span class="badge bg-primary"><i class="ri-cloud-line me-1"></i>Z-API</span>
                                            @else
                                                <span class="badge bg-secondary"><i class="ri-server-line me-1"></i>Local / Baileys</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($instance->user)
                                                <div>
                                                    {{ $instance->user->name }}
                                                    <br>
                                                    <small class="text-muted">{{ $instance->user->email }}</small>
                                                </div>
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($instance->agent)
                                                {{ $instance->agent->name }}
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($instance->phone_number)
                                                <span class="badge bg-info">{{ $instance->phone_number }}</span>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td>
                                            @switch($instance->status)
                                                @case('connected')
                                                    <span class="badge bg-success">
                                                        <i class="ri-check-line me-1"></i>Conectado
                                                    </span>
                                                @break

                                                @case('connecting')
                                                    <span class="badge bg-warning">
                                                        <i class="ri-loader-4-line me-1"></i>Conectando
                                                    </span>
                                                @break

                                                @case('disconnected')
                                                    <span class="badge bg-secondary">
                                                        <i class="ri-pause-circle-line me-1"></i>Desconectado
                                                    </span>
                                                @break

                                                @case('error')
                                                    <span class="badge bg-danger">
                                                        <i class="ri-error-warning-line me-1"></i>Erro
                                                    </span>
                                                @break

                                                @default
                                                    <span class="badge bg-secondary">{{ $instance->status }}</span>
                                            @endswitch

                                            @if ($instance->error_message)
                                                <br>
                                                <small class="text-danger" title="{{ $instance->error_message }}">
                                                    {{ Str::limit($instance->error_message, 30) }}
                                                </small>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($instance->connected_at)
                                                <small>{{ $instance->connected_at->format('d/m/Y H:i') }}</small>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($instance->last_activity)
                                                <small title="{{ $instance->last_activity->format('d/m/Y H:i:s') }}">
                                                    {{ $instance->last_activity->diffForHumans() }}
                                                </small>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-primary"
                                                    title="Escolher provedor ativo"
                                                    wire:click="openProviderConfig('{{ $instance->uuid }}')">
                                                    <i class="ri-settings-3-line"></i>
                                                </button>
                                                <!-- Reiniciar -->
                                                <button type="button" class="btn btn-sm btn-info"
                                                    title="Reiniciar Instância"
                                                    wire:click="restartInstance('{{ $instance->uuid }}')">
                                                    <i class="ri-refresh-line"></i>
                                                </button>
                                                @if ($instance->status === 'connected')
                                                    <!-- Desconectar -->
                                                    <button type="button" class="btn btn-sm btn-warning"
                                                        title="Desconectar Instância"
                                                        wire:click="disconnectInstance('{{ $instance->uuid }}')">
                                                        <i class="ri-pause-circle-line"></i>
                                                    </button>
                                                @endif

                                                <!-- Excluir -->
                                                <button type="button" class="btn btn-sm btn-danger"
                                                    title="Excluir Instância"
                                                    wire:click="showDeleteConfirm('{{ $instance->uuid }}', '{{ $instance->name ?? 'Instância ' . $instance->instance_id }}')">
                                                    <i class="ri-delete-bin-line"></i>
                                                </button>

                                                <!-- Ver Detalhes -->
                                                @if ($instance->agent)
                                                    <a href="{{ route('agents.manage', $instance->agent->uuid) }}"
                                                        class="btn btn-sm btn-primary" title="Gerenciar Chat">
                                                        <i class="ri-message-3-line"></i>
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center py-5">
                                                <i class="ri-whatsapp-line text-muted display-4"></i>
                                                <p class="text-muted mt-3">Nenhuma instância encontrada</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            {{ $instances->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div wire:ignore.self class="modal fade" id="whatsappProviderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Provedor ativo da instância</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small">
                    Somente o provedor selecionado poderá enviar, receber e consultar esta instância. A sessão local é preservada ao alternar para a Z-API.
                </div>

                <label class="form-label">Provedor</label>
                <select class="form-select mb-3" wire:model.live="activeProvider">
                    <option value="baileys">Local / Baileys</option>
                    <option value="zapi">Z-API</option>
                </select>

                @if ($activeProvider === 'zapi')
                    <label class="form-label">ID da instância Z-API</label>
                    <input type="text" class="form-control mb-3" wire:model="zapiInstanceId" placeholder="ID exibido no painel Z-API">
                    @error('zapiInstanceId') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                    <label class="form-label">Token da instância Z-API</label>
                    <input type="password" class="form-control mb-3" wire:model="zapiInstanceToken" placeholder="Informe para salvar ou deixe vazio para manter">

                    <label class="form-label">Client-Token da conta (opcional)</label>
                    <input type="password" class="form-control mb-2" wire:model="zapiClientToken" placeholder="Usa o token global se ficar vazio">
                    <div class="form-text mb-3">O token não é exibido novamente nem gravado em logs.</div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model="configureZapiWebhooks" id="configureZapiWebhooks">
                        <label class="form-check-label" for="configureZapiWebhooks">Configurar webhooks automaticamente</label>
                    </div>
                @else
                    <div class="alert alert-info small mb-0">
                        Ao salvar, o Baileys voltará a ser o transporte ativo. A autenticação local não será apagada.
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="saveProviderConfig">Salvar provedor</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <!-- Socket.io Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        const SOCKET_URL = (() => {
            const envUrl = '{{ env('WHATSAPP_SOCKET_URL', '') }}';
            if (envUrl && envUrl.trim() !== '') return envUrl.trim();
            const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
            if (isLocal && window.location.port !== '3000') {
                return `${window.location.protocol}//${window.location.hostname}:3000`;
            }
            return window.location.origin;
        })();
        const SOCKET_AUTH_TOKEN = @json(app(\App\Services\WhatsappSocketTokenService::class)->issue(auth()->user()));
        let socket = null;

        function initSocket() {
            if (socket) return;

            socket = io(SOCKET_URL, {
                auth: { token: SOCKET_AUTH_TOKEN },
                transports: ['websocket', 'polling'],
                reconnection: true,
                reconnectionAttempts: 3,
                reconnectionDelay: 1500
            });

            socket.on('connect', () => {
                console.log('✅ Socket conectado:', socket.id);
            });

            socket.on('status', (data) => {
                console.log('📡 Status recebido:', data);
                // Atualizar lista de instâncias
                Livewire.dispatch('instanceStatusUpdated', data);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            initSocket();
        });

        function registerWhatsappAdminHandlers() {
            if (window.__whatsappAdminHandlersRegistered) return;
            window.__whatsappAdminHandlersRegistered = true;

            Livewire.on('openWhatsappProviderConfig', () => {
                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('whatsappProviderModal'));
                modal.show();
            });

            Livewire.on('providerConfigSaved', () => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('whatsappProviderModal'));
                if (modal) modal.hide();
            });

            // Reiniciar instância
            Livewire.on('restartViaSocket', (data) => {
                const config = data[0] || data;
                console.log('🔄 Reiniciando instância:', config);

                if (socket && socket.connected) {
                    socket.emit('restart-instance', {
                        agentId: config.agentId,
                        userId: config.userId
                    });
                }
            });

            // Desconectar instância
            Livewire.on('disconnectViaSocket', (data) => {
                const config = data[0] || data;
                console.log('🔴 Desconectando instância:', config);

                if (socket && socket.connected) {
                    socket.emit('disconnectUser', config.agentId);
                }
            });

            // Confirmação de exclusão
            Livewire.on('showDeleteInstanceConfirm', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                const id = payload.id;
                const name = payload.name;

                Swal.fire({
                    title: 'Excluir Instância?',
                    text: `Tem certeza que deseja excluir "${name}"? Esta ação não pode ser desfeita.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch('deleteInstance', {
                            instanceId: id
                        });
                    }
                });
            });

            // Feedback de exclusão
            Livewire.on('instanceDeleted', (message) => {
                Swal.fire({
                    title: 'Sucesso!',
                    text: message,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                }).then(() => {
                    window.location.reload();
                });
            });

            // Feedback de erro
            Livewire.on('error', (message) => {
                message = Array.isArray(message) ? message[0] : message;
                Swal.fire({
                    title: 'Erro!',
                    text: message?.message || message,
                    icon: 'error',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#dc3545'
                });
            });
        }

        if (window.Livewire) {
            registerWhatsappAdminHandlers();
        } else {
            document.addEventListener('livewire:init', registerWhatsappAdminHandlers, { once: true });
        }
    </script>
@endpush
