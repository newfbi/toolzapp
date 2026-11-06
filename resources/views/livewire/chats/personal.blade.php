@php
    use Illuminate\Support\Str;
@endphp

<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Chat Pessoal',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Chat Pessoal',
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
                        <div class="p-5">
                            <div class="mb-2">
                                <div class="position-relative d-flex align-items-center justify-content-between">
                                    <h4 class="mb-0">Conversas</h4>
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

                            <!-- Botão para criar novo chat -->
                            <div class="mb-3">
                                <button type="button" class="w-100 btn btn-primary" wire:click="openCreateModal"
                                    wire:loading.attr="disabled" wire:target="openCreateModal">
                                    <span wire:loading.remove wire:target="openCreateModal">
                                        <i class="me-2 ri-add-line"></i> Novo Chat
                                    </span>
                                    <span wire:loading wire:target="openCreateModal">
                                        <span class="me-2 spinner-border spinner-border-sm" role="status"></span>
                                        Carregando...
                                    </span>
                                </button>
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
                                                    <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                                        @if ($chat->agent)
                                                            <img src="{{ $chat->agent->avatar ? '/assets/img/' . $chat->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                                class="rounded-circle avatar-lg" height="36"
                                                                alt="avatar">
                                                        @else
                                                            <!-- Ícone para chat com modelo -->
                                                            <div
                                                                class="d-flex align-items-center justify-content-center bg-success rounded-circle avatar-lg">
                                                                <i class="text-white ri-robot-line fs-5"></i>
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <h6 class="my-0">
                                                            <span
                                                                class="float-end text-body fs-12 fw-normal">{{ $chat->time }}</span>
                                                            @if ($chat->agent)
                                                                {{ $chat->agent->name ?? 'Agente não encontrado' }}
                                                            @elseif ($chat->selected_model)
                                                                <i
                                                                    class="me-1 ri-robot-line"></i>{{ $chat->selected_model }}
                                                            @else
                                                                Chat sem configuração
                                                            @endif
                                                        </h6>
                                                        <p
                                                            class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                            <span
                                                                class="w-75">{{ Str::limit($chat->last_message ?? 'Nenhuma mensagem', 30) }}</span>
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
                <div class="col-xxl-9 col-xl-8">
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
                                                <div id="selectedUserProfile" class="d-flex align-items-center gap-2">
                                                    <div id="selectedUserAvatarWrapper"
                                                        class="flex-shrink-0 avatar-wrapper active">
                                                        @if ($selectedChat->agent)
                                                            <img id="selectedUserAvatar"
                                                                src="{{ $selectedChat->agent->avatar ? '/assets/img/' . $selectedChat->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                                class="rounded-circle avatar-lg" alt="avatar image">
                                                        @elseif ($selectedChat->selected_model)
                                                            <div
                                                                class="d-flex align-items-center justify-content-center bg-success rounded-circle avatar-lg">
                                                                <i class="text-white ri-robot-line fs-4"></i>
                                                            </div>
                                                        @else
                                                            <img id="selectedUserAvatar"
                                                                src="/assets/images/avatar/default.png"
                                                                class="rounded-circle avatar-lg" alt="avatar image">
                                                        @endif
                                                        <span class="user-status"></span>
                                                    </div>
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <h5 id="selectedUserName" class="mb-0 text-truncate fs-15">
                                                            @if ($selectedChat->agent)
                                                                {{ $selectedChat->agent->name ?? 'Agente não encontrado' }}
                                                            @elseif ($selectedChat->selected_model)
                                                                <i
                                                                    class="me-2 ri-robot-line"></i>{{ $selectedChat->selected_model }}
                                                            @else
                                                                Chat sem configuração
                                                            @endif
                                                        </h5>
                                                        <p id="selectedUserStatus"
                                                            class="mb-0 text-muted text-truncate fs-14 userStatus">
                                                            <small>
                                                                @if ($selectedChat->agent)
                                                                    Online
                                                                @elseif ($selectedChat->selected_model)
                                                                    Modelo de IA
                                                                @else
                                                                    Status desconhecido
                                                                @endif
                                                            </small>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-5 chat-conversation" id="chat-conversation" data-simplebar
                                style="max-height: 60vh; overflow-y: auto;">
                                <ul id="chat-list" class="list-unstyled chat-conversation-list">
                                    @forelse ($messages as $message)
                                        <li wire:key="msg-{{ $message->id }}"
                                            class="chat-list {{ $message->direction === 'output' ? 'left' : 'right' }}">
                                            <div class="conversation-list">
                                                @if ($message->direction === 'output')
                                                    <div class="chat-avatar avatar-wrapper">
                                                        @if ($message->agent && $message->agent->avatar)
                                                            <img src="/assets/img/{{ $message->agent->avatar }}"
                                                                class="rounded-circle avatar-md" alt="avatar">
                                                        @elseif ($selectedChat && $selectedChat->selected_model)
                                                            <div
                                                                class="d-flex align-items-center justify-content-center bg-success rounded-circle avatar-md">
                                                                <i class="text-white ri-robot-line fs-6"></i>
                                                            </div>
                                                        @else
                                                            <img src="/assets/images/avatar/default.png"
                                                                class="rounded-circle avatar-md" alt="avatar">
                                                        @endif
                                                    </div>
                                                @endif

                                                <div class="user-chat-content">
                                                    <div class="ctext-wrap">
                                                        <div class="ctext-wrap-content">
                                                            @if ($message->audio)
                                                                <audio controls>
                                                                    <source src="/assets/{{ $message->audio }}"
                                                                        type="audio/mpeg">
                                                                    Seu navegador não suporta o elemento de áudio.
                                                                </audio>
                                                            @else
                                                                <p class="mb-0 ctext-content">{!! nl2br(e($message->content)) !!}
                                                                </p>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div class="conversation-name">
                                                        <small
                                                            class="text-muted time">{{ \Carbon\Carbon::parse($message->created_at)->format('H:i') }}</small>
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
                                    @empty
                                        <li class="py-5 text-center">
                                            <i class="text-muted ri-message-3-line display-1"></i>
                                            <h5 class="mt-4">Nenhuma Mensagem</h5>
                                            <p class="text-muted">Comece a conversar agora!</p>
                                        </li>
                                    @endforelse

                                    @if ($isAgentTyping)
                                        <li class="left chat-list">
                                            <div class="conversation-list">
                                                <div class="chat-avatar avatar-wrapper">
                                                    @if ($agent && $agent->avatar)
                                                        <img src="/assets/img/{{ $agent->avatar }}"
                                                            class="rounded-circle avatar-md" alt="avatar">
                                                    @elseif ($selectedChat && $selectedChat->selected_model)
                                                        <div
                                                            class="d-flex align-items-center justify-content-center bg-success rounded-circle avatar-md">
                                                            <i class="text-white ri-robot-line fs-6"></i>
                                                        </div>
                                                    @else
                                                        <img src="/assets/images/avatar/default.png"
                                                            class="rounded-circle avatar-md" alt="avatar">
                                                    @endif
                                                </div>
                                                <div class="user-chat-content">
                                                    <div class="ctext-wrap">
                                                        <div class="ctext-wrap-content">
                                                            <div class="typing-indicator">
                                                                <span></span>
                                                                <span></span>
                                                                <span></span>
                                                            </div>
                                                            @if ($typingMessage)
                                                                <p class="mt-2 mb-0 text-muted small">
                                                                    {{ $typingMessage }}</p>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                            <div class="p-5 border-top">
                                <div class="align-items-center row g-0">
                                    <div class="col">
                                        <input type="text" class="form-control" id="chat-input"
                                            placeholder="Digite sua mensagem..." autocomplete="off"
                                            wire:model="userMessage" wire:keydown.enter.prevent="sendMessage"
                                            {{ $isSending ? 'disabled' : '' }}>
                                    </div>
                                    <div class="col-auto">
                                        <div class="ms-2 chat-input-links">
                                            <div class="d-flex align-items-center gap-4">
                                                @if ($agent && ($agent->audio == 'enabled' || $agent->audio == true))
                                                    <a href="javascript:void(0);" id="record-audio"
                                                        class="text-body link fs-4" data-bs-toggle="tooltip"
                                                        data-bs-html="true" title="Gravar Áudio"
                                                        {{ $isSending ? 'disabled' : '' }}>
                                                        <i class="ri-mic-line"></i>
                                                    </a>
                                                @endif
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
                                                <button type="button" class="btn btn-light" wire:click="sendMessage"
                                                    {{ $isSending ? 'disabled' : '' }}>
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
                                    <p class="text-muted">Selecione um chat da lista ou crie um novo para começar</p>
                                    <button type="button" class="mt-3 btn btn-primary" wire:click="openCreateModal">
                                        <i class="me-2 ri-add-line"></i> Criar Novo Chat
                                    </button>
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

    <!-- Modal para criar novo chat -->
    <div class="modal fade @if ($showCreateModal) show @endif" id="createChatModal" tabindex="-1"
        role="dialog" aria-modal="true" wire:key="create-chat-modal-{{ $showCreateModal ? 'open' : 'closed' }}"
        style="@if ($showCreateModal) display: block !important; z-index: 1055; @else display: none; @endif">
        @if ($showCreateModal)
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Criar Novo Chat</h5>
                        <button type="button" class="btn-close" wire:click="closeCreateModal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @php
                            // Verificar se o usuário pode usar modelo (tem plano ativo OU não tem nenhum agente)
                            $hasActivePlan = $user->mensalidade() !== 'vencido' && $user->plan;
                            $userAgentsCount = \App\Models\Agents::where('user_id', $user->uuid)
                                ->where('status', '!=', 'draft')
                                ->count();
                            $canUseModel = $hasActivePlan || $userAgentsCount === 0;
                        @endphp

                        <!-- Seleção do tipo de chat -->
                        <div class="mb-3">
                            <label class="form-label">Tipo de Chat</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" id="chatModeAgent"
                                    wire:model.live="chatMode" value="agent" autocomplete="off">
                                <label class="btn-outline-primary btn" for="chatModeAgent">
                                    <i class="me-2 ri-user-line"></i>Usar Agente
                                </label>

                                <input type="radio" class="btn-check" id="chatModeModel"
                                    wire:model.live="chatMode" value="model" autocomplete="off"
                                    @if (!$canUseModel) disabled @endif>
                                <label class="btn-outline-success btn @if (!$canUseModel) opacity-50 @endif"
                                    for="chatModeModel" @if (!$canUseModel) title="Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para usar modelos." @endif>
                                    <i class="me-2 ri-robot-line"></i>Usar Modelo
                                </label>
                            </div>
                            @if (!$canUseModel)
                                <small class="d-block mt-2 text-muted">
                                    <i class="me-1 ri-information-line"></i>
                                    Você não possui plano ativo e já tem 1 agente criado. Atualize seu plano para usar modelos.
                                </small>
                            @endif
                        </div>

                        @if ($chatMode === 'agent')
                            <!-- Seleção de Agente -->
                            @if (empty($agents) || count($agents) === 0)
                                <div class="alert alert-warning">
                                    <i class="me-2 ri-alert-line"></i>
                                    Você não possui agentes disponíveis. Crie um agente primeiro ou escolha um modelo.
                                </div>
                            @else
                                <div class="mb-3">
                                    <label for="selectedAgentId" class="form-label">Selecione o Agente</label>
                                    <select class="form-select @error('selectedAgentId') is-invalid @enderror"
                                        id="selectedAgentId" wire:model.live="selectedAgentId"
                                        wire:loading.attr="disabled" required>
                                        <option value="">-- Selecione um agente --</option>
                                        @foreach ($agents as $agent)
                                            <option value="{{ $agent->uuid }}">{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('selectedAgentId')
                                        <div class="d-block invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    @if ($selectedAgentId)
                                        <div class="text-success form-text">
                                            <i class="me-1 ri-check-line"></i> Agente selecionado
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @elseif ($chatMode === 'model')
                            <!-- Seleção de Modelo -->
                            @if (empty(\App\Support\AiModelCatalog::all()))
                                <div class="alert alert-warning">
                                    <i class="me-2 ri-alert-line"></i>
                                    Nenhum modelo de conversação está configurado.
                                </div>
                            @else
                                <div class="mb-3">
                                    <label for="selectedModel" class="form-label">Selecione o Modelo</label>
                                    <select class="form-select @error('selectedModel') is-invalid @enderror"
                                        id="selectedModel" wire:model.live="selectedModel"
                                        wire:loading.attr="disabled" required>
                                        <option value="">-- Selecione um modelo --</option>
                                        @foreach (\App\Support\AiModelCatalog::all() as $modelId => $modelLabel)
                                            <option value="{{ $modelId }}">
                                                {{ $modelId }} - OpenAI ({{ $modelLabel }})
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('selectedModel')
                                        <div class="d-block invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    @if ($selectedModel)
                                        <div class="text-success form-text">
                                            <i class="me-1 ri-check-line"></i> Modelo selecionado
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeCreateModal"
                            wire:loading.attr="disabled">
                            Cancelar
                        </button>
                        @if (($chatMode === 'agent' && count($agents) > 0) || $chatMode === 'model')
                            <button type="button" class="btn btn-primary" wire:click="createChat"
                                wire:loading.attr="disabled" wire:target="createChat">
                                <span wire:loading.remove wire:target="createChat">Criar Chat</span>
                                <span wire:loading wire:target="createChat">
                                    <span class="me-2 spinner-border spinner-border-sm" role="status"
                                        aria-hidden="true"></span>
                                    Criando...
                                </span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
    <div class="modal-backdrop fade @if ($showCreateModal) show @endif" id="createChatModalBackdrop"
        style="z-index: 1050; @if ($showCreateModal) display: block !important; @else display: none; @endif">
    </div>
</div>

@push('scripts')
    <script>
        function scrollToBottomOfMessages(immediate = false) {
            const scrollFunction = () => {
                const container = document.getElementById('chat-conversation');
                if (!container) return;

                // Tentar com simplebar primeiro
                const simplebarContent = container.querySelector('.simplebar-content-wrapper');
                const scrollElement = simplebarContent || container;

                if (scrollElement) {
                    scrollElement.scrollTop = scrollElement.scrollHeight;
                }
            };

            if (immediate) {
                scrollFunction();
                // Também fazer após um pequeno delay para garantir
                setTimeout(scrollFunction, 50);
            } else {
                setTimeout(scrollFunction, 100);
            }
        }

        // Função para forçar scroll imediato
        function forceScrollToBottom() {
            scrollToBottomOfMessages(true);
            // Múltiplas tentativas para garantir
            setTimeout(() => scrollToBottomOfMessages(true), 100);
            setTimeout(() => scrollToBottomOfMessages(true), 300);
        }

        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => scrollToBottomOfMessages(), 500);
        });

        document.addEventListener('livewire:init', () => {
            setTimeout(() => scrollToBottomOfMessages(), 500);

            // Scroll após cada atualização do Livewire
            Livewire.hook('message.processed', ({
                component,
                message
            }) => {
                forceScrollToBottom();
            });

            // Scroll quando componente é atualizado
            Livewire.hook('morph.updated', ({
                el,
                component
            }) => {
                if (el.id === 'chat-conversation' || el.closest('#chat-conversation')) {
                    forceScrollToBottom();
                }
            });

            Livewire.on('chatChanged', () => {
                setTimeout(() => {
                    forceScrollToBottom();
                }, 300);
            });

            Livewire.on('clearInput', () => {
                forceScrollToBottom();
                const inputField = document.querySelector('input[wire\\:model="userMessage"]');
                if (inputField) {
                    inputField.value = '';
                }
            });

            // Processar resposta do agente com delay simulado
            // Usar variáveis globais para garantir acesso em todos os contextos
            if (!window.personalChatState) {
                window.personalChatState = {
                    responseProcessingTimeout: null,
                    fallbackTimeout: null,
                    verificationTimeout: null,
                    isResponseProcessing: false,
                    currentProcessingMessage: null
                };
            }

            const state = window.personalChatState;

            // Garantir que o listener seja registrado apenas uma vez
            if (!window.personalChatListenerRegistered) {
                window.personalChatListenerRegistered = true;

                Livewire.on('processAgentResponseDelayed', (event) => {
                    try {
                        console.log('PersonalChat: processAgentResponseDelayed recebido', event);

                        const data = event[0] || event;
                        const userMessage = data.userMessage;
                        const messageHash = userMessage ? btoa(userMessage).substring(0, 20) : null;

                        // Se já está processando a mesma mensagem, ignorar
                        if (state.isResponseProcessing && state.currentProcessingMessage === messageHash) {
                            console.warn(
                                'PersonalChat: Mensagem já está sendo processada, ignorando evento duplicado'
                            );
                            return;
                        }

                        // Armazenar evento para fallback
                        window.lastProcessAgentResponseEvent = event;

                        // Limpar TODOS os timeouts anteriores
                        if (state.responseProcessingTimeout) {
                            clearTimeout(state.responseProcessingTimeout);
                            state.responseProcessingTimeout = null;
                        }
                        if (state.fallbackTimeout) {
                            clearTimeout(state.fallbackTimeout);
                            state.fallbackTimeout = null;
                        }
                        if (state.verificationTimeout) {
                            clearTimeout(state.verificationTimeout);
                            state.verificationTimeout = null;
                        }

                        // Marcar como processando
                        state.isResponseProcessing = true;
                        state.currentProcessingMessage = messageHash;

                        const chatId = data.chatId;
                        const audioUser = data.audioUser || null;

                        if (!userMessage || !userMessage.trim()) {
                            console.error('PersonalChat: userMessage vazio no evento');
                            state.isResponseProcessing = false;
                            state.currentProcessingMessage = null;
                            return;
                        }

                        // Scroll imediato quando o evento é disparado
                        forceScrollToBottom();

                        // Simular delay natural (1-2 segundos - mais rápido)
                        const delay = Math.random() * 1000 + 1000; // Entre 1 e 2 segundos

                        // Atualizar mensagem de digitação durante o delay
                        const typingMessages = [
                            'Analisando sua mensagem...',
                            'Pensando na melhor resposta...',
                            'Finalizando resposta...'
                        ];

                        let currentMessageIndex = 0;
                        const typingInterval = setInterval(() => {
                            if (currentMessageIndex < typingMessages.length) {
                                @this.call('updateTypingMessage', typingMessages[
                                    currentMessageIndex]);
                                currentMessageIndex++;
                                // Scroll após cada atualização de mensagem
                                forceScrollToBottom();
                            } else {
                                clearInterval(typingInterval);
                            }
                        }, delay / typingMessages.length);

                        // Chamar método do Livewire após delay
                        state.responseProcessingTimeout = setTimeout(() => {
                            clearInterval(typingInterval);

                            // Verificação final antes de chamar
                            if (!state.isResponseProcessing || state.currentProcessingMessage !==
                                messageHash) {
                                console.warn('PersonalChat: Estado mudou, cancelando chamada', {
                                    isResponseProcessing: state.isResponseProcessing,
                                    currentMessage: state.currentProcessingMessage,
                                    expectedMessage: messageHash
                                });
                                return;
                            }

                            console.log('PersonalChat: Chamando processAgentResponseNow', {
                                userMessage: userMessage.substring(0, 50),
                                audioUser: audioUser,
                                delay: delay,
                                messageHash: messageHash
                            });

                            // Marcar como não processando ANTES de chamar para evitar duplicação
                            state.isResponseProcessing = false;

                            // Usar @this.call() do Livewire 3
                            @this.call('processAgentResponseNow', userMessage, audioUser).then(
                                () => {
                                    state.currentProcessingMessage = null;
                                    console.log(
                                        'PersonalChat: processAgentResponseNow concluído com sucesso'
                                    );
                                    forceScrollToBottom();

                                    // Limpar TODOS os timeouts
                                    if (state.fallbackTimeout) {
                                        clearTimeout(state.fallbackTimeout);
                                        state.fallbackTimeout = null;
                                    }
                                    if (state.verificationTimeout) {
                                        clearTimeout(state.verificationTimeout);
                                        state.verificationTimeout = null;
                                    }
                                }).catch((error) => {
                                console.error(
                                    'PersonalChat: Erro ao chamar processAgentResponseNow',
                                    error);
                                state.currentProcessingMessage = null;
                                // NÃO tentar novamente automaticamente - deixar o fallback cuidar disso
                                forceScrollToBottom();
                            });
                        }, delay);

                        // Fallback de segurança: Se após 3.5 segundos ainda estiver digitando, processar diretamente
                        state.fallbackTimeout = setTimeout(() => {
                            // Verificar múltiplas condições antes de executar fallback
                            const typingIndicator = document.querySelector('.typing-indicator');
                            const chatList = document.querySelector('#chat-list');
                            const lastMessage = chatList ? chatList.lastElementChild : null;
                            const isLastMessageFromAgent = lastMessage && lastMessage.classList
                                .contains('left');

                            // Se não está processando OU já há mensagem do agente OU não há indicador de digitação
                            if (!state.isResponseProcessing || isLastMessageFromAgent || !
                                typingIndicator) {
                                console.log(
                                    'PersonalChat: Fallback cancelado - resposta já foi processada', {
                                        isResponseProcessing: state.isResponseProcessing,
                                        isLastMessageFromAgent,
                                        hasTypingIndicator: !!typingIndicator
                                    });
                                state.isResponseProcessing = false;
                                state.currentProcessingMessage = null;
                                return;
                            }

                            // Verificar se ainda está processando a mesma mensagem
                            if (state.currentProcessingMessage === messageHash && typingIndicator &&
                                userMessage) {
                                console.warn(
                                    'PersonalChat: Fallback ativado - processando resposta diretamente após 3.5s'
                                );
                                // Marcar como não processando antes de chamar para evitar duplicação
                                state.isResponseProcessing = false;
                                // Tentar apenas uma vez
                                @this.call('processAgentResponseNow', userMessage, audioUser).then(
                                    () => {
                                        state.currentProcessingMessage = null;
                                    }).catch((error) => {
                                    console.error(
                                        'PersonalChat: Erro no fallback com processAgentResponseNow',
                                        error);
                                    state.currentProcessingMessage = null;
                                });
                            } else {
                                // Se não há indicador de digitação, já foi processado
                                state.isResponseProcessing = false;
                                state.currentProcessingMessage = null;
                            }
                        }, 3500);
                    } catch (error) {
                        console.error('PersonalChat: Erro ao processar processAgentResponseDelayed', error);
                        state.isResponseProcessing = false;
                        state.currentProcessingMessage = null;
                    }
                });
            }

            // Eventos de digitação com scroll imediato (fora do bloco para garantir acesso)
            Livewire.on('agentStartedTyping', () => {
                forceScrollToBottom();
            });

            Livewire.on('agentStoppedTyping', () => {
                // Limpar flags quando agente parar de digitar
                if (window.personalChatState) {
                    window.personalChatState.isResponseProcessing = false;
                    window.personalChatState.currentProcessingMessage = null;

                    // Limpar todos os timeouts
                    if (window.personalChatState.responseProcessingTimeout) {
                        clearTimeout(window.personalChatState.responseProcessingTimeout);
                        window.personalChatState.responseProcessingTimeout = null;
                    }
                    if (window.personalChatState.fallbackTimeout) {
                        clearTimeout(window.personalChatState.fallbackTimeout);
                        window.personalChatState.fallbackTimeout = null;
                    }
                    if (window.personalChatState.verificationTimeout) {
                        clearTimeout(window.personalChatState.verificationTimeout);
                        window.personalChatState.verificationTimeout = null;
                    }
                }

                forceScrollToBottom();
            });

            Livewire.on('agentMessageReceived', () => {
                // Limpar flags quando mensagem for recebida
                if (window.personalChatState) {
                    window.personalChatState.isResponseProcessing = false;
                    window.personalChatState.currentProcessingMessage = null;

                    // Limpar todos os timeouts
                    if (window.personalChatState.responseProcessingTimeout) {
                        clearTimeout(window.personalChatState.responseProcessingTimeout);
                        window.personalChatState.responseProcessingTimeout = null;
                    }
                    if (window.personalChatState.fallbackTimeout) {
                        clearTimeout(window.personalChatState.fallbackTimeout);
                        window.personalChatState.fallbackTimeout = null;
                    }
                    if (window.personalChatState.verificationTimeout) {
                        clearTimeout(window.personalChatState.verificationTimeout);
                        window.personalChatState.verificationTimeout = null;
                    }
                }

                forceScrollToBottom();
            });

            Livewire.on('userMessageSent', () => {
                // Resetar flags quando nova mensagem for enviada
                if (window.personalChatState) {
                    window.personalChatState.isResponseProcessing = false;
                    window.personalChatState.currentProcessingMessage = null;

                    // Limpar todos os timeouts anteriores
                    if (window.personalChatState.responseProcessingTimeout) {
                        clearTimeout(window.personalChatState.responseProcessingTimeout);
                        window.personalChatState.responseProcessingTimeout = null;
                    }
                    if (window.personalChatState.fallbackTimeout) {
                        clearTimeout(window.personalChatState.fallbackTimeout);
                        window.personalChatState.fallbackTimeout = null;
                    }
                    if (window.personalChatState.verificationTimeout) {
                        clearTimeout(window.personalChatState.verificationTimeout);
                        window.personalChatState.verificationTimeout = null;
                    }
                }

                forceScrollToBottom();
            });

            Livewire.on('userMessageSent', () => {
                // Resetar flags quando nova mensagem for enviada
                isResponseProcessing = false;
                currentProcessingMessage = null;

                // Limpar todos os timeouts anteriores
                if (responseProcessingTimeout) {
                    clearTimeout(responseProcessingTimeout);
                    responseProcessingTimeout = null;
                }
                if (fallbackTimeout) {
                    clearTimeout(fallbackTimeout);
                    fallbackTimeout = null;
                }
                if (verificationTimeout) {
                    clearTimeout(verificationTimeout);
                    verificationTimeout = null;
                }

                forceScrollToBottom();
            });

            Livewire.on('typingMessageUpdated', () => {
                forceScrollToBottom();
            });

            // Observer para detectar mudanças no DOM do chat
            const chatContainer = document.getElementById('chat-conversation');
            if (chatContainer) {
                const observer = new MutationObserver((mutations) => {
                    // Verificar se foi adicionado o indicador de digitação
                    mutations.forEach((mutation) => {
                        if (mutation.addedNodes.length > 0) {
                            mutation.addedNodes.forEach((node) => {
                                if (node.nodeType === 1) { // Element node
                                    if (node.classList && node.classList.contains(
                                            'typing-indicator')) {
                                        forceScrollToBottom();
                                    } else if (node.querySelector && node.querySelector(
                                            '.typing-indicator')) {
                                        forceScrollToBottom();
                                    }
                                }
                            });
                        }
                    });
                    scrollToBottomOfMessages(true);
                });

                observer.observe(chatContainer, {
                    childList: true,
                    subtree: true,
                    attributes: false
                });
            }

            // Observar mudanças na propriedade isAgentTyping
            let previousTypingState = false;
            setInterval(() => {
                const typingIndicator = document.querySelector('.typing-indicator');
                const currentTypingState = typingIndicator !== null;

                if (currentTypingState !== previousTypingState) {
                    previousTypingState = currentTypingState;
                    if (currentTypingState) {
                        forceScrollToBottom();
                    }
                }
            }, 200);
        });

        // Scroll imediato ao enviar mensagem
        document.addEventListener('DOMContentLoaded', () => {
            const sendButton = document.querySelector('button[wire\\:click="sendMessage"]');
            const chatInput = document.querySelector('input[wire\\:model="userMessage"]');

            if (sendButton) {
                sendButton.addEventListener('click', () => {
                    setTimeout(() => forceScrollToBottom(), 100);
                });
            }

            if (chatInput) {
                chatInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        setTimeout(() => forceScrollToBottom(), 100);
                    }
                });
            }
        });

        // Gravação de áudio - Estado global para persistir entre atualizações do Livewire
        if (!window.audioRecorderState) {
            window.audioRecorderState = {
                mediaRecorder: null,
                audioChunks: [],
                mediaStream: null,
                isRecording: false
            };
        }

        function initializeAudioRecorder() {
            const recordButton = document.getElementById('record-audio');
            if (!recordButton) {
                // Tentar novamente após um pequeno delay se o botão ainda não existir
                setTimeout(initializeAudioRecorder, 500);
                return;
            }

            // Remover listeners anteriores se existirem
            const newButton = recordButton.cloneNode(true);
            recordButton.parentNode.replaceChild(newButton, recordButton);

            const state = window.audioRecorderState;

            newButton.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                try {
                    if (!state.isRecording) {
                        // Iniciar gravação
                        console.log('PersonalChat: Iniciando gravação de áudio...');

                        // Solicitar permissão de microfone
                        state.mediaStream = await navigator.mediaDevices.getUserMedia({
                            audio: {
                                echoCancellation: true,
                                noiseSuppression: true,
                                sampleRate: 44100
                            }
                        });

                        // Criar MediaRecorder
                        const options = {
                            mimeType: 'audio/webm;codecs=opus'
                        };

                        // Verificar se o formato é suportado
                        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                            options.mimeType = 'audio/webm';
                            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                                options.mimeType = 'audio/ogg;codecs=opus';
                                if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                                    options.mimeType = '';
                                }
                            }
                        }

                        state.mediaRecorder = new MediaRecorder(state.mediaStream, options);
                        state.audioChunks = [];

                        state.mediaRecorder.ondataavailable = (event) => {
                            if (event.data && event.data.size > 0) {
                                state.audioChunks.push(event.data);
                                console.log('PersonalChat: Dados de áudio recebidos', event.data.size,
                                    'bytes');
                            }
                        };

                        state.mediaRecorder.onstop = () => {
                            console.log('PersonalChat: Gravação parada, processando áudio...');

                            if (state.audioChunks.length === 0) {
                                console.warn('PersonalChat: Nenhum dado de áudio capturado');
                                stopRecording();
                                return;
                            }

                            const audioBlob = new Blob(state.audioChunks, {
                                type: state.mediaRecorder.mimeType || 'audio/webm'
                            });

                            console.log('PersonalChat: Blob criado', audioBlob.size, 'bytes');

                            const reader = new FileReader();

                            reader.onloadend = () => {
                                console.log('PersonalChat: Áudio convertido para base64, enviando...');
                                Livewire.dispatch('sendAudioMessage', {
                                    audio: reader.result,
                                });
                                stopRecording();
                            };

                            reader.onerror = (error) => {
                                console.error('PersonalChat: Erro ao ler áudio', error);
                                stopRecording();
                            };

                            reader.readAsDataURL(audioBlob);
                        };

                        state.mediaRecorder.onerror = (event) => {
                            console.error('PersonalChat: Erro no MediaRecorder', event.error);
                            stopRecording();
                        };

                        // Iniciar gravação com timeslice para garantir dados frequentes
                        state.mediaRecorder.start(100); // Coletar dados a cada 100ms
                        state.isRecording = true;
                        newButton.classList.add('recording');
                        console.log('PersonalChat: Gravação iniciada com sucesso');

                    } else {
                        // Parar gravação
                        console.log('PersonalChat: Parando gravação...');
                        if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
                            state.mediaRecorder.stop();
                        } else {
                            stopRecording();
                        }
                    }
                } catch (error) {
                    console.error('PersonalChat: Erro ao acessar o microfone:', error);
                    alert('Erro ao acessar o microfone. Verifique as permissões do navegador.');
                    stopRecording();
                }
            });
        }

        function stopRecording() {
            const state = window.audioRecorderState;

            // Parar MediaRecorder se estiver ativo
            if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
                try {
                    state.mediaRecorder.stop();
                } catch (e) {
                    console.warn('PersonalChat: Erro ao parar MediaRecorder', e);
                }
            }

            // Parar todas as tracks do stream
            if (state.mediaStream) {
                state.mediaStream.getTracks().forEach(track => {
                    track.stop();
                });
                state.mediaStream = null;
            }

            // Limpar estado
            state.mediaRecorder = null;
            state.audioChunks = [];
            state.isRecording = false;

            // Remover classe visual
            const recordButton = document.getElementById('record-audio');
            if (recordButton) {
                recordButton.classList.remove('recording');
            }

            console.log('PersonalChat: Gravação finalizada e limpa');
        }

        // Inicializar quando o DOM estiver pronto
        document.addEventListener('DOMContentLoaded', () => {
            initializeAudioRecorder();
        });

        // Reinicializar após atualizações do Livewire
        document.addEventListener('livewire:init', () => {
            initializeAudioRecorder();
        });

        // Reinicializar após cada atualização do componente
        Livewire.hook('morph.updated', ({
            el,
            component
        }) => {
            if (el.id === 'record-audio' || el.closest('#record-audio') || document.getElementById(
                    'record-audio')) {
                setTimeout(() => {
                    initializeAudioRecorder();
                }, 100);
            }
        });

        // Debug e melhorias para o modal de criação de chat
        document.addEventListener('livewire:init', () => {
            // Listener para verificar quando o modal é aberto
            Livewire.on('modalOpened', () => {
                console.log('PersonalChat: Modal aberto');
            });

            // Verificar se o botão de criar chat está funcionando
            const createChatButton = document.querySelector('button[wire\\:click="openCreateModal"]');
            if (createChatButton) {
                createChatButton.addEventListener('click', (e) => {
                    console.log('PersonalChat: Botão de criar chat clicado');
                });
            }

            // Verificar se o botão de criar dentro do modal está funcionando
            Livewire.hook('morph.updated', ({
                el,
                component
            }) => {
                const createButton = document.querySelector('button[wire\\:click="createChat"]');
                if (createButton) {
                    createButton.addEventListener('click', (e) => {
                        console.log('PersonalChat: Botão criar chat no modal clicado');
                    });
                }
            });
        });

        // Debug e melhorias para o modal de criação de chat
        document.addEventListener('livewire:init', () => {
            // Verificar se o botão de criar chat está funcionando
            const createChatButton = document.querySelector('button[wire\\:click="openCreateModal"]');
            if (createChatButton) {
                createChatButton.addEventListener('click', (e) => {
                    console.log('PersonalChat: Botão de criar chat clicado');
                });
            }

            // Listener para verificar quando o modal é aberto
            Livewire.on('modalOpened', () => {
                console.log('PersonalChat: Modal aberto via evento');
                // Forçar exibição do modal usando JavaScript
                const modal = document.getElementById('createChatModal');
                const backdrop = document.getElementById('createChatModalBackdrop');

                if (modal) {
                    modal.classList.add('show');
                    modal.style.display = 'block';
                    modal.setAttribute('aria-hidden', 'false');
                }

                if (backdrop) {
                    backdrop.classList.add('show');
                }

                // Forçar foco no select quando o modal abrir
                setTimeout(() => {
                    const select = document.getElementById('selectedAgentId');
                    if (select) {
                        select.focus();
                    }
                }, 100);
            });

            // Listener para quando o modal é fechado
            Livewire.on('modalClosed', () => {
                console.log('PersonalChat: Modal fechado via evento');
                const modal = document.getElementById('createChatModal');
                const backdrop = document.getElementById('createChatModalBackdrop');

                if (modal) {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }

                if (backdrop) {
                    backdrop.classList.remove('show');
                }
            });

            // Observar mudanças na propriedade showCreateModal via Livewire hooks
            Livewire.hook('message.processed', ({
                component,
                message
            }) => {
                // Verificar se o componente tem a propriedade showCreateModal
                if (component && typeof component.get === 'function') {
                    try {
                        const showModal = component.get('showCreateModal');
                        const modal = document.getElementById('createChatModal');
                        const backdrop = document.getElementById('createChatModalBackdrop');

                        if (modal) {
                            if (showModal === true) {
                                console.log('PersonalChat: Mostrando modal via message.processed');
                                modal.classList.add('show');
                                modal.style.display = 'block';
                                modal.setAttribute('aria-hidden', 'false');
                                document.body.classList.add('modal-open');
                                if (backdrop) {
                                    backdrop.classList.add('show');
                                    backdrop.style.display = 'block';
                                }
                            } else if (showModal === false) {
                                console.log('PersonalChat: Escondendo modal via message.processed');
                                modal.classList.remove('show');
                                modal.style.display = 'none';
                                modal.setAttribute('aria-hidden', 'true');
                                document.body.classList.remove('modal-open');
                                if (backdrop) {
                                    backdrop.classList.remove('show');
                                    backdrop.style.display = 'none';
                                }
                            }
                        }
                    } catch (e) {
                        console.error('PersonalChat: Erro ao processar showCreateModal', e);
                    }
                }
            });

            // Fallback: Verificar periodicamente se o modal deve estar visível
            setInterval(() => {
                const modal = document.getElementById('createChatModal');
                const backdrop = document.getElementById('createChatModalBackdrop');

                if (modal) {
                    // Verificar se há conteúdo dentro do modal (indica que showCreateModal é true)
                    const hasContent = modal.querySelector('.modal-content');
                    const isVisible = modal.classList.contains('show') || modal.style.display === 'block';

                    if (hasContent && !isVisible) {
                        console.log('PersonalChat: Forçando exibição do modal (fallback)');
                        modal.classList.add('show');
                        modal.style.display = 'block';
                        modal.setAttribute('aria-hidden', 'false');
                        document.body.classList.add('modal-open');
                        if (backdrop) {
                            backdrop.classList.add('show');
                            backdrop.style.display = 'block';
                        }
                    }
                }
            }, 500);

            // Verificar se o botão de criar dentro do modal está funcionando
            Livewire.hook('morph.updated', ({
                el,
                component
            }) => {
                const createButton = document.querySelector('button[wire\\:click="createChat"]');
                if (createButton) {
                    // Remover listeners anteriores para evitar duplicação
                    const newButton = createButton.cloneNode(true);
                    createButton.parentNode.replaceChild(newButton, createButton);

                    newButton.addEventListener('click', (e) => {
                        console.log('PersonalChat: Botão criar chat no modal clicado');
                        const select = document.getElementById('selectedAgentId');
                        if (select && !select.value) {
                            e.preventDefault();
                            alert('Por favor, selecione um agente antes de criar o chat.');
                            select.focus();
                        }
                    });
                }
            });
        });
    </script>
@endpush

@push('styles')
    <style>
        .nav-link.recording i {
            color: red;
            animation: blink 1s infinite;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .form-control[disabled] {
            background-color: #f5f5f5;
            pointer-events: none;
        }

        /* Indicador de digitação */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 8px 12px;
            background-color: #f0f0f0;
            border-radius: 18px;
            width: fit-content;
        }

        .typing-indicator span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #999;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-indicator span:nth-child(1) {
            animation-delay: 0s;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typing {

            0%,
            60%,
            100% {
                transform: translateY(0);
                opacity: 0.7;
            }

            30% {
                transform: translateY(-10px);
                opacity: 1;
            }
        }
    </style>
@endpush
