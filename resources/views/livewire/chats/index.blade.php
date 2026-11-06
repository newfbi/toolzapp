<div class="container-fluid" wire:poll.3s="syncRealtimeChats">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Conversas',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Gerenciar Chats',
            ],
        ],
    ])

    <!-- Row -->
    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-sm-12 col-md-12 col-lg-12">
            <div class="row chat-wrapper g-0">
                <div class="border-end col-xxl-3 col-xl-4">
                    <div class="app-chat-sidebar active" id="app-chat-sidebar">
                        <div class="p-4">
                            <div class="mb-3">
                                <div class="position-relative d-flex align-items-center justify-content-between">
                                    <h4 class="mb-0 fs-16 font-weight-bold">
                                        <i class="ri-message-3-line me-1 text-primary"></i> Atendimentos
                                        <span class="badge bg-primary-subtle text-primary fs-11 ms-1">{{ count($chats) }}</span>
                                    </h4>
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

                            <!-- Barra de Pesquisa em Tempo Real -->
                            <div class="mb-2">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-0">
                                        <i class="ri-search-line text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control bg-light border-0" 
                                        placeholder="Buscar por contato, número..." 
                                        wire:model.live.debounce.300ms="search">
                                    @if ($search)
                                        <button class="btn btn-light border-0" type="button" wire:click="$set('search', '')">
                                            <i class="ri-close-line"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>

                            <!-- Filtros por Agente e Status -->
                            <div class="row g-1 mb-3">
                                <div class="col-7">
                                    <select class="form-select form-select-sm bg-light border-0 fs-11" wire:model.live="selectedAgentFilter">
                                        <option value="">Todos Agentes</option>
                                        @foreach ($availableAgents as $ag)
                                            <option value="{{ $ag->uuid }}">{{ $ag->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-5">
                                    <select class="form-select form-select-sm bg-light border-0 fs-11" wire:model.live="statusFilter">
                                        <option value="all">Status: Todos</option>
                                        <option value="active">🟢 IA Ativa</option>
                                        <option value="paused">⏸️ Pausados</option>
                                    </select>
                                </div>
                            </div>

                            <div class="text-muted tab-content">
                                <div class="tab-pane active show" id="chats" role="tabpanel">
                                    <div class="mx-n4 chat-room-list" data-simplebar style="max-height: calc(100vh - 270px); overflow-y: auto;">
                                        <ul id="chatList" class="mb-0 list-unstyled">
                                            @forelse ($chats as $chat)
                                                @php
                                                    $cleanPhone = str_replace(['@s.whatsapp.net', '@c.us', '@lid'], '', $chat->phone ?? '');
                                                    $displayPhone = $cleanPhone;
                                                    if (strlen($cleanPhone) === 13 && str_starts_with($cleanPhone, '55')) {
                                                        $displayPhone = '+55 (' . substr($cleanPhone, 2, 2) . ') ' . substr($cleanPhone, 4, 5) . '-' . substr($cleanPhone, 9);
                                                    } elseif (strlen($cleanPhone) === 12 && str_starts_with($cleanPhone, '55')) {
                                                        $displayPhone = '+55 (' . substr($cleanPhone, 2, 2) . ') ' . substr($cleanPhone, 4, 4) . '-' . substr($cleanPhone, 8);
                                                    } elseif (strlen($cleanPhone) === 11) {
                                                        $displayPhone = '(' . substr($cleanPhone, 0, 2) . ') ' . substr($cleanPhone, 2, 5) . '-' . substr($cleanPhone, 7);
                                                    }
                                                @endphp
                                                <li wire:click="selectChat('{{ $chat->uuid }}')"
                                                    class="d-flex align-items-center gap-3 mb-2 px-3 py-2 rounded-2 {{ $selectedChat && $selectedChat->uuid === $chat->uuid ? 'active bg-light' : '' }}"
                                                    style="cursor: pointer; transition: all 0.2s ease;">
                                                    <div class="position-relative flex-shrink-0 avatar-wrapper {{ $chat->is_pause == 'false' ? 'active' : '' }}">
                                                        <img src="{{ $chat->agent?->avatar ? '/assets/img/' . $chat->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                            class="rounded-circle avatar-md" height="34" alt="avatar">
                                                    </div>
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <div class="d-flex align-items-center justify-content-between">
                                                            <h6 class="my-0 text-truncate font-weight-bold fs-13 text-dark">
                                                                {{ $chat->name ?: 'Lead Sem Nome' }}
                                                            </h6>
                                                            <small class="text-muted fs-10">{{ $chat->time }}</small>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between mt-1">
                                                            <small class="text-muted fs-11">
                                                                <i class="ri-whatsapp-line text-success me-1"></i>{{ $displayPhone }}
                                                            </small>
                                                            @if ($chat->agent)
                                                                <span class="badge bg-primary-subtle text-primary fs-10 py-1 px-1">
                                                                    {{ $chat->agent->name }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                        <p class="d-flex align-items-center justify-content-between mt-1 mb-0 text-muted fs-11">
                                                            <span class="w-75 text-truncate">{{ $chat->last_message ?: 'Sem mensagens recentes' }}</span>
                                                            @if ($chat->is_pause == 'true')
                                                                <span class="badge bg-warning-subtle text-warning fs-10">Pausado</span>
                                                            @endif
                                                        </p>
                                                    </div>
                                                </li>
                                            @empty
                                                <li class="px-4 py-4 text-center text-muted">
                                                    <i class="ri-chat-delete-line fs-3 mb-1 d-block text-secondary"></i>
                                                    Nenhuma conversa encontrada
                                                </li>
                                            @endforelse
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-9 col-xl-8">
                    <div class="overflow-hidden">
                        @if ($selectedChat)
                            <div class="p-4 border-bottom">
                                <div class="align-items-center row">
                                    <div class="col-sm-6 col-8">
                                        <div class="d-flex align-items-center">
                                            <div class="d-block flex-shrink-0 me-3 d-xl-none">
                                                <a href="#!" class="btn btn-light icon-btn fs-5"
                                                    id="open-chat-sidebar">
                                                    <i class="ri-menu-2-fill"></i>
                                                </a>
                                            </div>
                                            <div class="flex-grow-1 overflow-hidden">
                                                <div id="selectedUserProfile" class="d-flex align-items-center gap-2">
                                                    <div id="selectedUserAvatarWrapper"
                                                        class="flex-shrink-0 avatar-wrapper {{ $selectedChat['is_pause'] == 'false' ? 'active' : '' }}">
                                                        <img id="selectedUserAvatar"
                                                            src="{{ $agent?->avatar ? '/assets/img/' . $agent->avatar : '/assets/images/avatar/default.png' }}"
                                                            class="rounded-circle avatar-lg" alt="avatar image">
                                                        <span class="user-status"></span>
                                                    </div>
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <h5 id="selectedUserName" class="mb-0 text-truncate fs-15 font-weight-bold">
                                                            {{ $selectedChat['name'] }}
                                                        </h5>
                                                        <p id="selectedUserStatus"
                                                            class="mb-0 text-muted text-truncate fs-12 userStatus">
                                                            <small>
                                                                <i class="ri-whatsapp-line text-success me-1"></i>{{ str_replace(['@s.whatsapp.net', '@c.us', '@lid'], '', $selectedChat['phone'] ?? '') }}
                                                                <span class="mx-1">•</span> Agente: <strong>{{ $agent?->name ?? 'Geral' }}</strong>
                                                                <span class="mx-1">•</span> 
                                                                @if ($selectedChat['is_pause'] == 'true')
                                                                    <span class="text-warning fw-semibold">⏸️ IA Pausada (Atendimento Manual)</span>
                                                                @else
                                                                    <span class="text-success fw-semibold">🟢 IA Ativa (Respondendo)</span>
                                                                @endif
                                                            </small>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 col-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            <!-- Botão Rápido de Pausar/Ativar IA -->
                                            <button type="button" class="btn btn-sm {{ $selectedChat['is_pause'] == 'false' ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                wire:click="pauseAgent('{{ $selectedChat['uuid'] }}')"
                                                title="{{ $selectedChat['is_pause'] == 'false' ? 'Pausar IA para intervir manualmente' : 'Reativar resposta automática da IA' }}">
                                                <i class="{{ $selectedChat['is_pause'] == 'false' ? 'ri-pause-circle-line' : 'ri-play-circle-line' }} me-1"></i>
                                                <span class="d-sm-inline d-none">
                                                    {{ $selectedChat['is_pause'] == 'false' ? 'Pausar IA' : 'Reativar IA' }}
                                                </span>
                                            </button>

                                            <!-- Botão de Status da Instância -->
                                            <button type="button" class="btn-outline-info btn btn-sm"
                                                wire:click="checkInstanceStatus"
                                                title="Verificar Status da Instância WhatsApp">
                                                <i class="bi bi-info-circle"></i>
                                                <span class="d-sm-inline d-none">Status</span>
                                            </button>

                                            <div class="dropdown">
                                                <a href="javascript:void(0)" class="btn btn-light icon-btn btn-sm"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </a>
                                                <div class="dropdown-menu dropdown-menu-end">
                                                    <a class="dropdown-item" href="javascript:void(0)"
                                                        wire:click="pauseAgent('{{ $selectedChat['uuid'] }}')">
                                                        @if ($selectedChat['is_pause'] == 'false')
                                                            Pausar Agente
                                                        @else
                                                            Reativar Agente
                                                        @endif
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item" href="javascript:void(0)"
                                                        wire:click="getCurrentInstanceInfo">
                                                        <i class="me-2 bi bi-info-circle"></i>
                                                        Info da Instância
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
                                                            <p class="mb-0 ctext-content">{{ $message->content }}</p>
                                                        </div>
                                                    </div>
                                                    <div class="conversation-name">
                                                        <small class="text-muted time">{{ $message->time }}</small>
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
                                <div class="align-items-center row g-0">
                                    <div class="col">
                                        <input type="text" class="form-control" id="chat-input"
                                            placeholder="Digite sua mensagem..." autocomplete="off"
                                            wire:model="agentMessage" wire:keydown.enter.prevent="sendMessage">
                                    </div>
                                    <div class="col-auto">
                                        <div class="ms-2 chat-input-links">
                                            <div class="d-flex align-items-center gap-4">
                                                <a href="#!" class="text-body link fs-4"
                                                    data-bs-toggle="tooltip" data-bs-html="true" title="Anexar Link">
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
                    <div class="app-chat-overlay" id="appChatOverlay"></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row end -->
</div>

@push('styles')
    <style>
        /* Avatar pausado - indicador cinza */
        .avatar-wrapper:not(.active) .user-status {
            background-color: #6c757d !important;
            /* Cinza para pausado */
        }

        /* Avatar pausado na lista de chats */
        .avatar-wrapper:not(.active)::after {
            background-color: #6c757d !important;
            /* Cinza para pausado */
        }
    </style>
@endpush

@push('scripts')
    <!-- Socket.io Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script>
        /**
         * 📜 CONTROLE DE SCROLL DO CHAT
         * Garante que o scroll permaneça sempre fixado na última mensagem enviada/recebida
         */
        const ChatScroll = {
            scroll(smooth = false) {
                const container = document.getElementById('chat-conversation');
                if (!container) return;

                // 1. Procurar último elemento de mensagem na lista
                const chatList = document.getElementById('chat-list');
                const lastMsg = chatList ? chatList.lastElementChild : null;

                if (lastMsg) {
                    lastMsg.scrollIntoView({
                        behavior: smooth ? 'smooth' : 'instant',
                        block: 'end'
                    });
                }

                // 2. Fallback de scrollTop direto para container nativo e SimpleBar
                const simplebarWrapper = container.querySelector('.simplebar-content-wrapper');
                if (simplebarWrapper) {
                    simplebarWrapper.scrollTop = simplebarWrapper.scrollHeight + 2000;
                }
                container.scrollTop = container.scrollHeight + 2000;
            },

            // Executa em múltiplos frames para contornar o recálculo assíncrono do SimpleBar
            forceBottom(smooth = false) {
                requestAnimationFrame(() => this.scroll(smooth));
                setTimeout(() => this.scroll(smooth), 60);
                setTimeout(() => this.scroll(false), 180);
                setTimeout(() => this.scroll(false), 400);
            }
        };

        // Observer para rolar automaticamente sempre que novas mensagens forem inseridas no DOM
        let chatObserver = null;
        function initChatObserver() {
            if (chatObserver) chatObserver.disconnect();
            const chatList = document.getElementById('chat-list');
            if (!chatList) return;

            chatObserver = new MutationObserver(() => {
                ChatScroll.forceBottom(true);
            });

            chatObserver.observe(chatList, { childList: true, subtree: true });
        }

        /**
         * 🌐 CONTROLE DE WEBSOCKET (SOCKET.IO)
         * Conexão e escuta em tempo real para todas as instâncias e mensagens
         * Resolução dinâmica: se estiver em produção (HTTPS), conecta no mesmo domínio via rota /socket.io
         */
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
        let socketInitialized = false;
        let currentAgentId = null;
        let connectedAgents = new Set();

        function initSocket() {
            if (socketInitialized) return;
            socketInitialized = true;

            socket = io(SOCKET_URL, {
                auth: { token: SOCKET_AUTH_TOKEN },
                transports: ['websocket', 'polling'],
                reconnection: true,
                reconnectionAttempts: 5,
                reconnectionDelay: 1500,
                autoConnect: true
            });

            socket.on('connect', () => {
                console.log('✅ [SOCKET] Conectado:', socket.id);

                // Ingressar nas salas de todos os agentes do usuário para capturar qualquer mensagem em tempo real
                const allAgents = @json($availableAgents ? collect($availableAgents)->pluck('uuid')->toArray() : []);
                if (allAgents && allAgents.length > 0) {
                    socket.emit('join-agents', { agentIds: allAgents });
                    allAgents.forEach(id => connectedAgents.add(id));
                }

                // Sincronizar status dos agentes ativos
                connectedAgents.forEach(agentId => {
                    socket.emit('get-session-status', { agentId: agentId });
                });
            });

            socket.on('connect_error', (err) => {
                console.error('❌ [SOCKET] Erro:', err.message);
            });

            socket.on('disconnect', (reason) => {
                console.warn('🔌 [SOCKET] Desconectado:', reason);
            });

            // Mensagem recebida ou enviada em tempo real
            socket.on('message', (data) => {
                console.log('📨 [SOCKET] Mensagem recebida:', data);
                Livewire.dispatch('messageReceived', { payload: data });
                ChatScroll.forceBottom(true);
            });

            socket.on('status', (statusData) => {
                if (statusData.agent_id && connectedAgents.has(statusData.agent_id)) {
                    Livewire.dispatch('updateStatus', {
                        status: statusData.status,
                        agent_id: statusData.agent_id
                    });
                }
            });

            socket.on('session-status', (data) => {
                if (data.agent_id && connectedAgents.has(data.agent_id)) {
                    Livewire.dispatch('updateStatus', {
                        status: data.status,
                        agent_id: data.agent_id
                    });
                }
            });

            socket.on('message-sent', (data) => {
                Livewire.dispatch('messageSentConfirmation', { data: data });
                ChatScroll.forceBottom(true);
            });

            socket.on('instance-status', (data) => {
                Livewire.dispatch('instanceStatusResponse', { data: data });
            });
        }

        function connectInstance(agentId) {
            initSocket();
            if (!socket || !socket.connected || !agentId) return;

            if (!connectedAgents.has(agentId)) {
                connectedAgents.add(agentId);
                socket.emit('join-agents', { agentIds: [agentId] });
            }
            socket.emit('get-session-status', { agentId: agentId });
        }

        function disconnectInstance(agentId) {
            if (socket && socket.connected && agentId) {
                socket.emit('disconnectUser', agentId);
                connectedAgents.delete(agentId);
            }
        }

        /**
         * 🎨 ATUALIZAÇÕES VISUAIS DA INTERFACE
         */
        function updateStatusVisual() {
            const selectedChat = @this.selectedChat;
            if (!selectedChat) return;

            const avatarWrapper = document.getElementById('selectedUserAvatarWrapper');
            const statusText = document.getElementById('selectedUserStatus');

            if (avatarWrapper && statusText) {
                const isPaused = (selectedChat.is_pause === 'true' || selectedChat.is_pause === true || (typeof selectedChat === 'object' && selectedChat.is_pause == 'true'));
                avatarWrapper.classList.toggle('active', !isPaused);
            }
        }

        /**
         * ⚡ INTEGRAÇÃO COM CICLO DE VIDA DO LIVEWIRE
         */
        document.addEventListener('livewire:init', () => {
            initSocket();
            initChatObserver();
            ChatScroll.forceBottom(false);

            // Ao mudar de chat selecionado
            Livewire.on('chatChanged', (data) => {
                const eventData = data?.[0] || data || {};
                const newAgentId = eventData.agentId || @this.agentId;

                if (newAgentId && newAgentId !== currentAgentId) {
                    currentAgentId = newAgentId;
                    connectInstance(newAgentId);
                }

                setTimeout(() => {
                    initChatObserver();
                    ChatScroll.forceBottom(false);
                }, 80);
            });

            // Disparo explícito de rolagem para o fim
            Livewire.on('scrollDown', () => {
                ChatScroll.forceBottom(true);
            });

            // Limpa o input e rola para o fim ao enviar
            Livewire.on('clearInput', () => {
                const inputField = document.getElementById('chat-input');
                if (inputField) inputField.value = '';
                ChatScroll.forceBottom(true);
            });

            Livewire.on('disconnectWhats', (data) => {
                const agentId = data?.[0] || data;
                disconnectInstance(agentId);
            });

            Livewire.on('send-wpp-message', (payload) => {
                if (socket && socket.connected) {
                    socket.emit('send-message', payload[0]);
                }
                ChatScroll.forceBottom(true);
            });

            Livewire.on('check-instance-status', (payload) => {
                if (socket && socket.connected) {
                    socket.emit('check-instance-status', {
                        ...payload,
                        userId: @json($userId)
                    });
                }
            });

            // Após qualquer requisição/morph do Livewire, garantir scroll e observação
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    initChatObserver();
                    ChatScroll.forceBottom(false);
                    updateStatusVisual();
                });
            });

            Livewire.hook('morph.updated', () => {
                initChatObserver();
                ChatScroll.forceBottom(false);
                updateStatusVisual();
            });

            // Conexão inicial
            setTimeout(() => {
                const initialAgentId = @this.agentId;
                if (initialAgentId) {
                    currentAgentId = initialAgentId;
                    connectInstance(initialAgentId);
                }
                ChatScroll.forceBottom(false);
            }, 300);
        });

        // Garantir scroll após carregamento completo da página
        document.addEventListener('DOMContentLoaded', () => {
            ChatScroll.forceBottom(false);
            setTimeout(() => ChatScroll.forceBottom(false), 600);
        });
    </script>
@endpush
