<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Mensagens Prontas WhatsApp',
        'breadcrumbItems' => [
            ['label' => 'Home', 'url' => route('dashboard')],
            ['label' => 'WhatsApp'],
            ['label' => 'Mensagens Prontas'],
        ],
    ])

    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <!-- Cabeçalho -->
        <div class="mb-3 col-12">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <p class="mb-0 text-muted">
                    Crie mensagens prontas com variáveis (@{{ nome }}, @{{ email }}...) para enviar aos
                    seus leads da Lista de Usuários.
                </p>
                <button type="button" wire:click="openCreateModal" class="btn btn-primary">
                    <i class="me-2 ri-add-line"></i>Novo Layout
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="mb-3 col-12">
            <div class="d-flex gap-2">
                <input type="text" wire:model.debounce.300ms="search" class="form-control"
                    placeholder="Buscar por nome ou conteúdo..." style="max-width: 320px;">
                <select wire:model="filterStatus" class="form-select" style="max-width: 160px;">
                    <option value="">Todos Status</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </select>
            </div>
        </div>

        <!-- Grid de layouts -->
        @forelse ($layouts as $layout)
            <div class="mb-3 col-md-6 col-xl-4">
                <div class="card h-100 {{ $layout->status === 'active' ? '' : 'opacity-75' }}">
                    <div class="d-flex align-items-center justify-content-between card-header">
                        <div class="text-truncate me-2">
                            <h6 class="mb-0 text-truncate">{{ $layout->name }}</h6>
                        </div>
                        <button wire:click="toggleStatus('{{ $layout->uuid }}')"
                            class="badge border-0 {{ $layout->status === 'active' ? 'bg-success' : 'bg-secondary' }}"
                            title="Clique para alterar o status">
                            {{ $layout->status === 'active' ? 'Ativo' : 'Inativo' }}
                        </button>
                    </div>
                    <div class="card-body">
                        @if ($layout->hasImage() && $layout->getImageDisplayUrl())
                            <img src="{{ $layout->getImageDisplayUrl() }}" alt="" class="w-100 rounded mb-2"
                                style="max-height: 110px; object-fit: cover;">
                        @endif
                        {{-- Prévia do conteúdo com variáveis renderizadas --}}
                        <p class="mb-3 p-2 bg-light rounded small text-pre-wrap">
                            {{ \App\Models\WhatsappLayout::renderContent(Str::limit($layout->message, 180), [
                                'nome' => 'João Silva', 'primeiro_nome' => 'João', 'email' => 'joao@email.com',
                                'telefone' => '(11) 99999-8888', 'status' => 'Ativo',
                            ]) }}
                        </p>
                        <small class="text-muted d-block mb-1">
                            <i class="me-1 ri-file-list-3-line"></i>{{ Str::length($layout->message) }} caracteres
                        </small>
                        <small class="text-muted d-block">
                            <i class="me-1 ri-time-line"></i>Atualizado em {{ $layout->updated_at->format('d/m/Y H:i') }}
                        </small>
                    </div>
                    <div class="card-footer text-end">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-primary"
                                wire:click="openEditModal('{{ $layout->uuid }}')" title="Editar">
                                <i class="ri-edit-line"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-info"
                                wire:click="duplicate('{{ $layout->uuid }}')" title="Duplicar">
                                <i class="ri-file-copy-line"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger"
                                wire:click="showDeleteConfirm('{{ $layout->uuid }}', '{{ $layout->name }}')"
                                title="Excluir">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="py-5 text-center card">
                    <div class="card-body">
                        <i class="display-4 text-muted ri-message-square-quote-line"></i>
                        <h5 class="mt-3">Nenhum layout criado</h5>
                        <p class="text-muted mb-3">Crie sua primeira mensagem pronta para agilizar o contato com os
                            leads.</p>
                        <button type="button" class="btn btn-primary" wire:click="openCreateModal">
                            <i class="me-2 ri-add-line"></i>Criar Primeiro Layout
                        </button>
                    </div>
                </div>
            </div>
        @endforelse

        <!-- Modal editor -->
        <div class="modal fade" id="layoutEditorModal" tabindex="-1"
            wire:ignore.self>
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ $layoutId ? 'Editar Layout' : 'Novo Layout' }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="closeEditor"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Nome do layout*</label>
                                <input type="text" wire:model="name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    placeholder="Ex.: Primeiro contato - apresentação">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">Variáveis — clique para inserir na mensagem</label>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach ($variables as $variable)
                                        @php $token = '{{' . $variable['key'] . '}}'; @endphp
                                        <button type="button"
                                            class="badge bg-light text-dark border user-select-none"
                                            style="cursor: pointer;"
                                            onclick="insertVariable(@js($token))"
                                            title="{{ $variable['label'] }} (ex.: {{ $variable['example'] }})">
                                            {{ $token }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label">Mensagem/legenda* <small class="text-muted">(*negrito*, _itálico_,
                                        ~riscado~, ```mono```)</small></label>
                                <textarea wire:model.live="message" id="layoutMessageInput" rows="8"
                                    class="form-control @error('message') is-invalid @enderror"
                                    placeholder="Olá @{{ primeiro_nome }}, confira: https://site.com/promo"></textarea>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted d-none d-md-block"><i class="ri-information-line me-1"></i>Clique nas variáveis acima para inserir</small>
                                    <small class="ms-auto {{ strlen($message) > ($maxMessageLength - 200) ? 'text-danger' : 'text-muted' }}">
                                        {{ strlen($message) }}/{{ $maxMessageLength }}
                                    </small>
                                </div>
                                @error('message')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror

                                <hr class="my-3">

                                <label class="form-label">Imagem anexa <small class="text-muted">(o texto/link é enviado
                                        como legenda da imagem)</small></label>

                                {{-- Prévia da imagem atual --}}
                                @if ($this->currentImageDisplayUrl())
                                    <div class="position-relative mb-2">
                                        <img src="{{ $this->currentImageDisplayUrl() }}" alt="Imagem anexa"
                                            class="img-thumbnail" style="max-height: 140px;">
                                        <button type="button" wire:click="removeImage" wire:loading.attr="disabled"
                                            class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1"
                                            title="Remover imagem">
                                            <i class="ri-close-line"></i>
                                        </button>
                                    </div>
                                @endif

                                {{-- Upload --}}
                                <input type="file" wire:model="newImage"
                                    accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                                    class="form-control form-control-sm @error('newImage') is-invalid @enderror">
                                @error('newImage')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div wire:loading wire:target="newImage" class="text-muted small mt-1">
                                    <i class="spinner-border spinner-border-sm me-1"></i>Enviando imagem...
                                </div>

                                {{-- Ou URL externa --}}
                                <div class="input-group input-group-sm mt-2">
                                    <span class="input-group-text"><i class="ri-link"></i></span>
                                    <input type="url" wire:model="externalImageUrl" class="form-control"
                                        placeholder="ou cole o link de uma imagem (https://...)">
                                </div>
                                @error('externalImageUrl')
                                    <div class="text-danger small">{{ $message }}</div>
                                @enderror

                                <small class="text-muted d-block mt-1">
                                    <i class="me-1 ri-information-line"></i>JPG, PNG, WEBP ou GIF — até 5 MB
                                </small>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">Prévia estilo WhatsApp</label>
                                <div class="p-2 rounded border" style="min-height: 220px; background: #e5ddd5;">
                                    <div class="bg-white rounded-3 p-2 shadow-sm small"
                                        style="max-width: 92%; margin-left: auto;">
                                        @if ($this->preview['imageUrl'])
                                            <img src="{{ $this->preview['imageUrl'] }}" alt="Imagem"
                                                class="w-100 rounded mb-2" style="max-height: 170px; object-fit: cover;">
                                        @endif
                                        @if (trim(strip_tags($this->preview['text'])) === '')
                                            <span class="text-muted">A prévia aparece conforme você escreve...</span>
                                        @else
                                            {!! $this->preview['text'] !!}
                                        @endif
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    <i class="me-1 ri-eye-line"></i>Renderiza como aparecerá para o lead — links
                                    clicáveis e formatação aplicada
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeEditor">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="save"
                            wire:loading.attr="disabled" wire:target="save">
                            {{ $layoutId ? 'Salvar Alterações' : 'Criar Layout' }}
                            <i class="spinner-border spinner-border-sm ms-1" wire:loading wire:target="save"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paginação -->
        <div class="col-12">
            <div class="mt-2">{{ $layouts->links() }}</div>
        </div>
    </div>
</div>

@section('js')
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.addEventListener('livewire:init', function() {

            // Abre o modal do editor
            Livewire.on('openLayoutEditor', function() {
                const modal = new bootstrap.Modal(document.getElementById('layoutEditorModal'));
                modal.show();
            });

            // Fecha o modal do editor
            Livewire.on('closeLayoutEditor', function() {
                const el = document.getElementById('layoutEditorModal');
                const modal = bootstrap.Modal.getInstance(el);
                if (modal) modal.hide();
            });

            // Insere variável na posição do cursor (dispara input p/ Livewire sincronizar)
            window.insertVariable = function(text) {
                const el = document.getElementById('layoutMessageInput');
                if (!el) return;
                el.focus();
                el.setRangeText(text, el.selectionStart, el.selectionEnd, 'end');
                el.dispatchEvent(new Event('input', { bubbles: true }));
            };

            // Confirmação de exclusão
            Livewire.on('showDeleteLayoutConfirm', function(data) {
                const layout = data[0];

                Swal.fire({
                    title: 'Excluir Layout?',
                    text: `Tem certeza que deseja excluir "${layout.name}"? Esta ação não pode ser desfeita.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        Livewire.dispatch('deleteLayout', { layoutId: layout.id });
                    }
                });
            });

            // Feedback de exclusão
            Livewire.on('layoutDeleted', function(message) {
                Swal.fire({
                    title: 'Sucesso!',
                    text: message,
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
            });

            // Feedback de erro genérico
            Livewire.on('error', function(message) {
                Swal.fire({
                    title: 'Erro!',
                    text: message,
                    icon: 'error',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#dc3545'
                });
            });
        });
    </script>
@endsection
