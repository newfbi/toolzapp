<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Agente',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Agentes',
                'url' => route('agentes'),
            ],
            [
                'label' => 'Gerenciar Agente',
            ],
        ],
    ])

    <!-- Row -->
    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')
        @if (!$whatsappInstance || !$whatsappInstance->isConnected())
            <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12 col-xxl-12">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <h1 class="me-3">Conectar ao WhatsApp</h1>
                            @if ($whatsappInstance)
                                <div
                                    class="badge {{ $whatsappInstance->isConnected() ? 'bg-success' : ($whatsappInstance->isConnecting() ? 'bg-warning' : 'bg-secondary') }}">
                                    {{ $whatsappInstance->isConnected() ? 'Conectado' : ($whatsappInstance->isConnecting() ? 'Conectando' : 'Desconectado') }}
                                </div>
                            @else
                                <div class="badge bg-secondary">Sem Instância</div>
                            @endif
                        </div>

                        @if (!$whatsappInstance)
                            <div class="alert alert-warning">
                                <strong>Atenção!</strong> Nenhuma instância WhatsApp configurada para este agente.
                                <a href="{{ route('whatsapp.instances') }}" class="ms-2 btn btn-sm btn-primary">
                                    Criar Instância
                                </a>
                            </div>
                        @else
                            @if ($whatsappInstance->hasError())
                                <div class="alert alert-danger">
                                    <strong>Erro:</strong> {{ $whatsappInstance->error_message }}
                                </div>
                            @endif

                            @if ($showQrModal && $qrCodeData)
                                <div class="alert alert-info">
                                    <strong>Status:</strong> Aguardando leitura do QR Code
                                </div>
                                <p>Escaneie o QR Code abaixo para conectar sua conta do WhatsApp:</p>
                                <div class="mb-3 text-center">
                                    <img src="{{ $qrCodeData }}" alt="QR Code do WhatsApp" class="img-fluid"
                                        style="max-width: 300px;">
                                </div>
                                <div class="mb-3 text-center">
                                    <button class="btn-outline-danger btn btn-sm" wire:click="closeQrModal">
                                        <i class="ri-close-line"></i> Cancelar Conexão
                                    </button>
                                </div>
                                <div class="text-center">
                                    <p class="text-muted">
                                        <small><i class="ri-time-line"></i> QR Code atualiza automaticamente</small>
                                    </p>
                                </div>
                            @elseif ($whatsappInstance->isConnecting())
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Conectando...</span>
                                    </div>
                                    <p class="mt-2">Aguardando QR Code...</p>
                                </div>
                            @else
                                <div class="alert alert-info">
                                    <strong>Status:</strong> Instância desconectada
                                </div>
                                <div class="text-center">
                                    <button class="btn btn-primary" wire:click="connectInstance">
                                        <i class="ri-qr-code-line"></i> Gerar QR Code e Conectar
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12 col-xxl-12">
                <div class="row chat-wrapper g-0">
                    <div class="border-end col-xxl-3 col-xl-3">
                        <div class="app-chat-sidebar active" id="app-chat-sidebar">
                            <div class="p-5">
                                <div class="mb-2">
                                    <div class="position-relative d-flex align-items-center justify-content-between">
                                        <h4 class="mb-0">Chats da Instancia</h4>
                                        <div class="position-absolute d-xl-none" style="right: -38px;"
                                            data-bs-toggle="tooltip" data-bs-trigger="hover" data-bs-placement="bottom"
                                            aria-label="Fechar" data-bs-original-title="Fechar">
                                            <a href="javascript:void(0)" class="bg-light p-2 rounded-pill text-success"
                                                id="close-chat-sidebar">
                                                <i class="ri-arrow-left-double-fill fs-5"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <ul class="my-4 nav nav-tabs-bordered nav-justified" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <a class="nav-link active" data-bs-toggle="tab" href="#chats" role="tab"
                                            aria-selected="true">
                                            Chats
                                        </a>
                                    </li>
                                </ul>
                                <div class="text-muted tab-content">
                                    <div class="tab-pane active show" id="chats" role="tabpanel">
                                        <div class="mx-n5 chat-room-list" data-simplebar>
                                            <ul id="chatList" class="mb-0 list-unstyled">
                                                @foreach ($chats as $chat)
                                                    <li wire:click="selectChat('{{ $chat->uuid }}')"
                                                        class="d-flex align-items-center gap-3 mb-2 px-4 py-3 {{ $selectedChat && $selectedChat->uuid === $chat->uuid ? 'active' : '' }}"
                                                        style="cursor: pointer;">
                                                        <div
                                                            class="position-relative flex-shrink-0 avatar-wrapper {{ $agent->status === 'enabled' ? 'active' : '' }}">
                                                            <img src="{{ $chat->agent->avatar ? '/assets/img/' . $chat->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                                class="rounded-circle avatar-lg" height="36"
                                                                alt="avatar">
                                                        </div>
                                                        <div class="flex-grow-1 overflow-hidden">
                                                            <h6 class="my-0">
                                                                <span
                                                                    class="float-end text-body fs-12 fw-normal">{{ date('d/m/Y', strtotime($chat->time)) }}</span>
                                                                {{ str_replace('@c.us', '', $chat->phone) }}
                                                            </h6>
                                                            <p
                                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                                <span
                                                                    class="w-75">{{ Str::limit($chat->last_message, 30) }}</span>
                                                                @if (($chat->messages_count ?? 0) > 0)
                                                                    <span
                                                                        class="bg-success-subtle text-success badge">{{ $chat->messages_count }}</span>
                                                                @endif
                                                            </p>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-9 col-xl-9">
                        <div class="overflow-hidden">
                            @if ($selectedChat)
                                <div class="p-5 border-bottom">
                                    <div class="align-items-center row">
                                        <div class="col-sm-4 col-8">
                                            <div class="d-flex align-items-center">
                                                <div class="d-block flex-shrink-0 me-4 d-xl-none">
                                                    <a href="#!" class="btn btn-light icon-btn fs-5"
                                                        id="open-chat-sidebar">
                                                        <i class="ri-menu-2-fill"></i>
                                                    </a>
                                                </div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <div id="selectedUserProfile"
                                                        class="d-flex align-items-center gap-2">
                                                        <div id="selectedUserAvatarWrapper"
                                                            class="flex-shrink-0 avatar-wrapper {{ $agent->status === 'enabled' ? 'active' : '' }}">
                                                            <img id="selectedUserAvatar"
                                                                src="/assets/images/avatar/default.png"
                                                                class="rounded-circle avatar-lg" alt="avatar image">
                                                            <span class="user-status"></span>
                                                        </div>
                                                        <div class="flex-grow-1 overflow-hidden">
                                                            <h5 id="selectedUserName"
                                                                class="mb-0 text-truncate fs-15">
                                                                {{ $selectedChat['name'] }}
                                                            </h5>
                                                            <p id="selectedUserStatus"
                                                                class="mb-0 text-muted text-truncate fs-14 userStatus">
                                                                <small>{{ $agent->status === 'enabled' ? 'Online' : 'Offline (Agente Desabilitado)' }}</small>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-8 col-4">
                                            <div class="d-flex justify-content-end gap-2">
                                                <div class="dropdown">
                                                    <a href="javascript:void(0)" class="btn btn-light icon-btn"
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </a>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        <a class="dropdown-item"
                                                            href="{{ route('chats.view', ['chatUuid' => $selectedChat['uuid']]) }}"
                                                            target="_blank" rel="noopener">
                                                            Abrir Chat
                                                        </a>
                                                        <a class="dropdown-item" href="javascript:void(0)"
                                                            wire:click="pauseAgent('{{ $selectedChat['uuid'] }}')">
                                                            @if ($selectedChat['is_pause'] == 'false')
                                                                Pausar Agente
                                                            @else
                                                                Reativar Agente
                                                            @endif
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-5 chat-conversation" id="chat-conversation" data-simplebar
                                    style="max-height: 60vh; overflow-y: auto;">
                                    <ul id="chat-list" class="list-unstyled chat-conversation-list">
                                        @foreach ($messages as $message)
                                            <li wire:key="msg-{{ $message->id }}"
                                                class="chat-list {{ $message->direction === 'output' ? 'left' : 'right' }}">
                                                <div class="conversation-list">
                                                    @if ($message->direction === 'output')
                                                        <div class="chat-avatar avatar-wrapper">
                                                            <img src="{{ $message->agent->avatar ? '/assets/img/' . $message->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                                class="rounded-circle avatar-md" alt="avatar">
                                                        </div>
                                                    @endif

                                                    <div class="user-chat-content">
                                                        <div class="ctext-wrap">
                                                            <div class="ctext-wrap-content">
                                                                <p class="mb-0 ctext-content">{{ $message->content }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="conversation-name">
                                                            <small
                                                                class="text-muted time">{{ $message->time }}</small>
                                                            <span class="text-success check-message-icon">
                                                                <i class="bi bi-check-double"></i>
                                                            </span>
                                                        </div>
                                                    </div>

                                                    @if ($message->direction !== 'output')
                                                        <div class="chat-avatar avatar-wrapper">
                                                            <img src="/assets/images/avatar/default.png"
                                                                class="rounded-circle avatar-md" alt="avatar">
                                                        </div>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div class="p-5 border-top">
                                    @if ($agent->status !== 'enabled')
                                        {{-- Agente desabilitado - não pode enviar mensagens --}}
                                        <div class="alert alert-danger mb-3">
                                            <i class="ri-alert-line me-2"></i>
                                            <strong>Agente Desabilitado:</strong> O agente está desativado.
                                            Ative o agente para poder enviar mensagens.
                                        </div>
                                        <div class="align-items-center row g-0">
                                            <div class="col">
                                                <input type="text" class="form-control" id="chat-input"
                                                    placeholder="Ative o agente para enviar mensagens..."
                                                    autocomplete="off" disabled>
                                            </div>
                                            <div class="col-auto">
                                                <div class="ms-2">
                                                    <button type="button" class="btn btn-light" disabled>
                                                        <i class="bi bi-send-fill"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @elseif ($selectedChat['is_pause'] == 'false')
                                        {{-- Chat com agente ativo - não pode enviar mensagens --}}
                                        <div class="alert alert-warning mb-3">
                                            <i class="ri-alert-line me-2"></i>
                                            <strong>Agente Ativo:</strong> O agente está respondendo automaticamente
                                            neste chat.
                                            Pause o agente para intervir manualmente.
                                        </div>
                                        <div class="align-items-center row g-0">
                                            <div class="col">
                                                <input type="text" class="form-control" id="chat-input"
                                                    placeholder="Pause o agente para enviar mensagens..."
                                                    autocomplete="off" disabled>
                                            </div>
                                            <div class="col-auto">
                                                <div class="ms-2">
                                                    <button type="button" class="btn btn-light" disabled>
                                                        <i class="bi bi-send-fill"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        {{-- Agente habilitado e chat pausado - pode enviar mensagens --}}
                                        <div class="alert alert-info mb-3">
                                            <i class="ri-information-line me-2"></i>
                                            <strong>Modo Manual:</strong> O agente está pausado neste chat. Você pode
                                            enviar
                                            mensagens manualmente.
                                        </div>
                                        <div class="align-items-center row g-0">
                                            <div class="col">
                                                <input type="text" class="form-control" id="chat-input"
                                                    placeholder="Digite sua mensagem..." autocomplete="off"
                                                    wire:model="agentMessage"
                                                    wire:keydown.enter.prevent="sendMessage">
                                            </div>
                                            <div class="col-auto">
                                                <div class="ms-2 chat-input-links">
                                                    <div class="d-flex align-items-center gap-4">
                                                        <a href="#!" class="text-body link fs-4"
                                                            data-bs-toggle="tooltip" data-bs-html="true"
                                                            title="Anexar Link">
                                                            <i class="ri-link-m"></i>
                                                        </a>
                                                        <div class="file-upload">
                                                            <a href="#!" class="text-body link fs-4"
                                                                data-bs-toggle="tooltip" data-bs-html="true"
                                                                title="Adicionar Arquivo">
                                                                <input class="file-upload-item" type="file">
                                                                <i class="ri-file-add-line"></i>
                                                            </a>
                                                        </div>
                                                        <a href="#!" class="text-body link fs-4"
                                                            data-bs-toggle="tooltip" data-bs-html="true"
                                                            title="Enviar Emoji">
                                                            <i class="ri-emoji-sticker-line"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <div class="ms-2">
                                                    <div class="links-list-item">
                                                        <button type="button" class="btn btn-light"
                                                            wire:click="sendMessage">
                                                            <i class="bi bi-send-fill"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="p-5 text-center">
                                    <div class="py-5">
                                        <i class="text-muted ri-message-3-line display-1"></i>
                                        <h5 class="mt-4">Nenhum Chat Selecionado</h5>
                                        <p class="text-muted">Selecione um chat da lista para iniciar a conversa</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="app-chat-overlay" id="appChatOverlay"></div>
                </div>
            </div>
        @endif
    </div>
    <!-- Row end -->
</div>

@push('styles')
    <style>
        /* Avatar offline - sem indicador verde */
        .avatar-wrapper:not(.active) .user-status {
            background-color: #6c757d !important;
            /* Cinza para offline */
        }

        /* Avatar offline na lista de chats */
        .avatar-wrapper:not(.active)::after {
            background-color: #6c757d !important;
            /* Cinza para offline */
        }

        /* Desabilitar hover em inputs desabilitados */
        input:disabled {
            cursor: not-allowed;
            background-color: #f8f9fa;
        }

        /* Estilos para alertas */
        .alert-danger {
            border-left: 4px solid #dc3545;
        }

        .alert-warning {
            border-left: 4px solid #ffc107;
        }

        .alert-info {
            border-left: 4px solid #0dcaf0;
        }
    </style>
@endpush

@push('scripts')
    <!-- Socket.io Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>

    <script>
        // ========================================
        // CONFIGURAÇÃO DO SOCKET.IO
        // ========================================
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
        const AGENT_ID = '{{ $agentId }}';
        const USER_ID = '{{ $userId }}';
        @if ($whatsappInstance)
            console.log($whatsappInstance);
            const INSTANCE_ID = '{{ $whatsappInstance->uuid }}';
        @else
            const INSTANCE_ID = null;
        @endif

        let socket = null;
        let socketInitialized = false;

        /**
         * Inicializa a conexão Socket.io
         */
        function initSocket() {
            if (socketInitialized) {
                console.log('Socket já inicializado');
                return;
            }

            console.log('🔌 Inicializando Socket.io para agente:', AGENT_ID);

            socketInitialized = true;

            socket = io(SOCKET_URL, {
                auth: { token: SOCKET_AUTH_TOKEN },
                transports: ['websocket', 'polling'],
                reconnection: true,
                reconnectionAttempts: 5,
                reconnectionDelay: 1500,
                autoConnect: true
            });

            // Evento: Conexão estabelecida
            socket.on('connect', () => {
                console.log('✅ Socket conectado:', socket.id);

                // Se houver instância, conectar automaticamente
                if (INSTANCE_ID && AGENT_ID) {
                    console.log('🔄 Conectando instância automaticamente:', AGENT_ID);
                    socket.emit('initialize', {
                        userId: USER_ID,
                        agentId: AGENT_ID
                    });
                }
            });

            // Evento: Erro de conexão
            socket.on('connect_error', (err) => {
                console.error('❌ Erro Socket.io:', err.message);
            });

            // Evento: Desconexão
            socket.on('disconnect', (reason) => {
                console.warn('🔌 Socket desconectado:', reason);
            });

            // Evento: Status da instância
            socket.on('status', (data) => {
                console.log('📡 Status recebido:', data);
                handleStatusUpdate(data);
            });

            // Evento: QR Code gerado
            socket.on('qr-code', (data) => {
                console.log('📱 QR Code recebido');
                Livewire.dispatch('instanceStatusUpdated', {
                    ...data,
                    status: 'qr'
                });
            });

            // Evento: Autenticado
            socket.on('authenticated', (data) => {
                console.log('✅ Autenticado');
                Livewire.dispatch('instanceStatusUpdated', {
                    ...data,
                    status: 'authenticated'
                });
            });

            // Evento: WhatsApp pronto
            socket.on('ready', (data) => {
                console.log('🎉 WhatsApp pronto!');
                Livewire.dispatch('instanceStatusUpdated', {
                    ...data,
                    status: 'ready'
                });
            });

            // Evento: Mensagem recebida
            socket.on('message-received', (data) => {
                console.log('💬 Mensagem recebida:', data);
                handleIncomingMessage(data);
            });

            // Evento: Mensagem enviada
            socket.on('message-sent', (data) => {
                console.log('✅ Mensagem enviada:', data);
            });

            // Evento: Erro ao enviar mensagem
            socket.on('message-error', (data) => {
                console.error('❌ Erro ao enviar mensagem:', data);
                alert('Erro ao enviar mensagem: ' + (data.error || 'Erro desconhecido'));
            });

            // Armazenar socket globalmente
            window.socket = socket;
        }

        /**
         * Handler para atualizações de status
         */
        function handleStatusUpdate(data) {
            if (!data || !data.agent_id) {
                console.warn('⚠️ Evento status inválido:', data);
                return;
            }

            // Só processar se for do agente atual
            if (data.agent_id !== AGENT_ID) {
                console.log('⚠️ Status de outro agente, ignorando');
                return;
            }

            console.log('✅ Processando status:', data);

            const instanceId = INSTANCE_ID || data.instance_id || null;

            const updateData = {
                agent_id: data.agent_id,
                instanceId: instanceId,
                status: data.status,
                phoneNumber: data.phone_number || null,
                qrCode: data.qr_url || null,
                requires_new_qr: data.requires_new_qr || false,
                message: data.message || null
            };

            Livewire.dispatch('instanceStatusUpdated', updateData);
        }

        /**
         * Handler para mensagens recebidas
         */
        function handleIncomingMessage(data) {
            console.log('📨 Processando mensagem recebida:', data);

            // Verificar se é do agente atual
            if (data.agent_id !== AGENT_ID && data.agentId !== AGENT_ID) {
                console.log('⚠️ Mensagem de outro agente, ignorando');
                return;
            }

            // Disparar evento Livewire para atualizar a interface
            Livewire.dispatch('messageReceived', {
                from: data.from,
                message: data.message,
                timestamp: data.timestamp
            });
        }

        // ========================================
        // FUNÇÕES DE SCROLL
        // ========================================
        function scrollToBottomOfMessages() {
            setTimeout(() => {
                const container = document.getElementById('chat-conversation');
                if (container) {
                    const simplebarContent = container.querySelector('.simplebar-content-wrapper');
                    if (simplebarContent) {
                        simplebarContent.scrollTop = simplebarContent.scrollHeight;
                    } else {
                        container.scrollTop = container.scrollHeight;
                    }
                }
            }, 200);
        }

        // ========================================
        // INICIALIZAÇÃO
        // ========================================
        document.addEventListener('DOMContentLoaded', () => {
            // Inicializar Socket.io
            initSocket();

            // Scroll inicial
            setTimeout(() => scrollToBottomOfMessages(), 500);
        });

        document.addEventListener('livewire:init', () => {
            const inputField = document.querySelector('[wire\\:model="agentMessage"]');

            setTimeout(() => scrollToBottomOfMessages(), 500);

            Livewire.hook('message.processed', () => {
                scrollToBottomOfMessages();
            });

            Livewire.on('chatChanged', () => {
                setTimeout(() => {
                    const container = document.getElementById('chat-conversation');
                    if (container) {
                        const simplebarContent = container.querySelector(
                            '.simplebar-content-wrapper');
                        if (simplebarContent) {
                            simplebarContent.scrollTop = simplebarContent.scrollHeight;
                        } else {
                            container.scrollTop = container.scrollHeight;
                        }
                    }

                    // Atualizar status visual do avatar e texto
                    updateChatStatus();
                }, 500);
            });

            /**
             * Atualiza o status visual do chat (online/offline)
             */
            function updateChatStatus() {
                // Esta função será chamada após mudanças no estado do chat
                // O Livewire já atualiza o DOM, então apenas garantimos que os estilos sejam aplicados
                const avatarWrapper = document.getElementById('selectedUserAvatarWrapper');
                if (avatarWrapper) {
                    // O Livewire já atualizou as classes, apenas forçamos um repaint
                    avatarWrapper.style.display = 'none';
                    avatarWrapper.offsetHeight; // Trigger reflow
                    avatarWrapper.style.display = '';
                }
            }

            Livewire.on('clearInput', () => {
                scrollToBottomOfMessages();
                if (inputField) {
                    inputField.value = '';
                }
            });

            // ========================================
            // LIVEWIRE SOCKET.IO INTEGRATION
            // ========================================

            // Conectar instância via Socket.io
            Livewire.on('connectViaSocket', (data) => {
                const config = data[0] || data;
                console.log('🔌 Conectando via Socket.io:', config);

                if (!socket || !socket.connected) {
                    console.error('❌ Socket não está conectado');
                    initSocket();
                    setTimeout(() => {
                        if (socket && socket.connected) {
                            socket.emit('initialize', {
                                userId: config.userId,
                                agentId: config.agentId
                            });
                        }
                    }, 1000);
                    return;
                }

                socket.emit('initialize', {
                    userId: config.userId,
                    agentId: config.agentId
                });
            });

            // Desconectar instância via Socket.io
            Livewire.on('disconnectViaSocket', (data) => {
                const config = data[0] || data;
                console.log('🔴 Desconectando via Socket.io:', config);

                if (socket && socket.connected) {
                    socket.emit('disconnectUser', config.agentId);
                }
            });

            // Reiniciar instância via Socket.io
            Livewire.on('restartViaSocket', (data) => {
                const config = data[0] || data;
                console.log('🔄 Reiniciando via Socket.io:', config);

                if (socket && socket.connected) {
                    socket.emit('restart-instance', {
                        agentId: config.agentId,
                        userId: config.userId
                    });
                } else {
                    console.error('❌ Socket não está conectado');
                }
            });

            // Enviar mensagem via Socket.io
            Livewire.on('sendMessageViaSocket', (data) => {
                const config = data[0] || data;
                console.log('📤 Enviando mensagem via Socket.io:', config);

                if (!socket || !socket.connected) {
                    console.error('❌ Socket não está conectado');
                    alert('Erro: Socket não está conectado. Tente reconectar.');
                    return;
                }

                socket.emit('send-message', {
                    agentId: config.agentId,
                    userId: USER_ID,
                    user_id: USER_ID,
                    phone: config.phone,
                    to: config.to || config.phone,
                    from: config.from || null,
                    recipient_type: config.recipient_type || 'phone',
                    message: config.message
                });
            });

            // Listener para modal fechado
            Livewire.on('modalClosed', () => {
                console.log('🚪 Modal de QR Code fechado');
            });
        });

        // Limpar socket ao sair da página
        window.addEventListener('beforeunload', () => {
            if (socket) {
                console.log('🔌 Desconectando socket ao sair da página');
                socket.disconnect();
            }
        });
    </script>
@endpush
