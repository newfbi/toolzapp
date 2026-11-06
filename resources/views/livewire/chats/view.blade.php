<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Painel de Conversas',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Chats',
                'url' => route('agentes'),
            ],
            [
                'label' => 'Chat Online',
            ],
        ],
    ])

    <!-- Row -->
    <div class="row row-sm">
        <div class="col-9 chat-wrapper">
            <div class="overflow-hidden">
                <div class="p-5 border-bottom">
                    <div class="align-items-center row">
                        <div class="col-sm-8 col-8">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="flex-shrink-0 avatar-wrapper active">
                                            <img src="{{ $agent->avatar ? '/assets/img/' . $agent->avatar : '/assets/images/avatar/default.png' }}"
                                                class="rounded-circle avatar-lg" alt="avatar image">
                                            <span class="user-status"></span>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="mb-0 text-truncate fs-15">
                                                {{ $agent->name ?? 'Chat' }}
                                            </h5>
                                            <p class="mb-0 text-muted text-truncate fs-14">
                                                <small>Online</small>
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
                                            <img src="{{ $agent->avatar ? '/assets/img/' . $agent->avatar : '/assets/images/avatar/default.png' }}"
                                                class="rounded-circle avatar-md" alt="avatar">
                                        </div>
                                    @endif

                                    <div class="user-chat-content">
                                        <div class="ctext-wrap">
                                            <div class="ctext-wrap-content">
                                                @if ($message->audio)
                                                    <audio controls>
                                                        <source src="/assets/{{ $message->audio }}" type="audio/mpeg">
                                                        Seu navegador não suporta o elemento de áudio.
                                                    </audio>
                                                @else
                                                    <p class="mb-0 ctext-content">{!! nl2br(e($message->content)) !!}</p>
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
                    </ul>
                </div>
                <div class="p-5 border-top">
                    <div class="align-items-center row g-0">
                        <div class="col">
                            <input type="text" class="form-control" id="chat-input"
                                placeholder="Digite sua mensagem..." autocomplete="off" wire:model="userMessage"
                                wire:keydown.enter.prevent="sendMessage" {{ $isSending ? 'disabled' : '' }}>
                        </div>
                        <div class="col-auto">
                            <div class="ms-2 chat-input-links">
                                <div class="d-flex align-items-center gap-4">
                                    @if ($agent->audio == 'enabled')
                                        <a href="javascript:void(0);" id="record-audio" class="text-body link fs-4"
                                            data-bs-toggle="tooltip" data-bs-html="true" title="Gravar Áudio"
                                            {{ $isSending ? 'disabled' : '' }}>
                                            <i class="ri-mic-line"></i>
                                        </a>
                                    @endif
                                    <div class="file-upload">
                                        <a href="#!" class="text-body link fs-4" data-bs-toggle="tooltip"
                                            data-bs-html="true" title="Adicionar Arquivo">
                                            <input class="file-upload-item" type="file">
                                            <i class="ri-file-add-line"></i>
                                        </a>
                                    </div>
                                    <a href="#!" class="text-body link fs-4" data-bs-toggle="tooltip"
                                        data-bs-html="true" title="Enviar Emoji">
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
            </div>
        </div>
    </div>
    <!-- Row end -->
</div>

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

        .main-msg-send[disabled] {
            opacity: 0.5;
            pointer-events: none;
        }

        .form-control[disabled] {
            background-color: #f5f5f5;
            pointer-events: none;
        }
    </style>
@endpush

@push('scripts')
    <!-- Socket.io Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script>
        function scrollToBottomOfMessages() {
            setTimeout(() => {
                const container = document.getElementById('chat-conversation');
                if (container) {
                    // Se usar simplebar, precisa acessar o elemento interno
                    const simplebarContent = container.querySelector('.simplebar-content-wrapper');
                    if (simplebarContent) {
                        simplebarContent.scrollTop = simplebarContent.scrollHeight;
                    } else {
                        container.scrollTop = container.scrollHeight;
                    }
                }
            }, 200);
        }

        // Scroll imediato quando o DOM carregar
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => scrollToBottomOfMessages(), 500);
        });

        document.addEventListener('livewire:init', () => {
            // Scroll inicial com delay maior
            setTimeout(() => scrollToBottomOfMessages(), 500);

            // Atualiza scroll quando novas mensagens são processadas
            Livewire.hook('message.processed', () => {
                scrollToBottomOfMessages();
            });

            // Atualiza scroll quando chat muda
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
                }, 500);
            });

            // Limpa o input e ajusta o scroll ao enviar mensagens
            Livewire.on('clearInput', () => {
                scrollToBottomOfMessages();
                const inputField = document.querySelector('input[wire\\:model="userMessage"]');
                if (inputField) {
                    inputField.value = '';
                }
            });
        });
        document.addEventListener('DOMContentLoaded', () => {
            const recordButton = document.getElementById('record-audio');
            if (!recordButton) return;

            let mediaRecorder;
            let audioChunks = [];
            recordButton.addEventListener('click', async () => {
                if (!mediaRecorder || mediaRecorder.state === 'inactive') {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({
                            audio: true
                        });
                        mediaRecorder = new MediaRecorder(stream);

                        mediaRecorder.ondataavailable = (event) => {
                            if (event.data.size > 0) {
                                audioChunks.push(event.data);
                                //console.log('Dados de áudio capturados:', event.data);
                            }
                        };

                        mediaRecorder.onstop = () => {
                            const audioBlob = new Blob(audioChunks, {
                                type: 'audio/webm'
                            });
                            const reader = new FileReader();

                            reader.onloadend = () => {
                                // Enviar o áudio codificado como Base64 para o Livewire
                                Livewire.dispatch('sendAudioMessage', {
                                    audio: reader.result,
                                });
                            };

                            reader.readAsDataURL(audioBlob); // Converte o Blob em Base64
                            audioChunks = [];
                        };

                        mediaRecorder.start();
                        recordButton.classList.add('recording');
                        //console.log('Gravação iniciada');
                    } catch (error) {
                        console.error('Erro ao acessar o microfone:', error);
                    }
                } else if (mediaRecorder.state === 'recording') {
                    mediaRecorder.stop();
                    recordButton.classList.remove('recording');
                    //console.log('Gravação finalizada');
                }
            });
        });
    </script>
@endpush
