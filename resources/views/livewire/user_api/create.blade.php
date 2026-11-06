<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar APIs',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Gerenciar APIs',
            ],
        ],
    ])

    <!-- Row -->
    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <!-- Header com botão de adicionar -->
        <div class="mb-4 col-12">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-0">Suas APIs Configuradas</h4>
                    <p class="mb-0 text-muted">Gerencie suas chaves de API para diferentes plataformas de IA</p>
                </div>
                @if ($availablePlatforms->count() > 0)
                    <button wire:click="openModal" class="btn btn-primary">
                        <i class="ri-add-line"></i> Adicionar API
                    </button>
                @endif
            </div>
        </div>

        <!-- Lista de APIs -->
        @if ($apis->count() > 0)
            @foreach ($apis as $api)
                <div class="mb-4 col-xl-3 col-lg-4">
                    <div class="h-100 card custom-card">
                        <div class="d-flex align-items-center justify-content-between card-header">
                            <div class="d-flex align-items-center">
                                <div class="me-3 avatar avatar-sm">
                                    <div
                                        class="avatar-initial bg-{{ $api->is_active ? 'success' : 'secondary' }} rounded-circle">
                                        <i class="ri-key-line"></i>
                                    </div>
                                </div>
                                <div>
                                    <h6 class="mb-0">{{ $api->platform->name ?? 'Plataforma' }}</h6>
                                    <small class="text-muted">{{ $api->platform_slug }}</small>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn-outline-secondary btn btn-sm dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown">
                                    <i class="ri-more-2-line"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <button wire:click="toggleApiStatus({{ $api->id }})"
                                            class="dropdown-item {{ $api->is_active ? 'text-warning' : 'text-success' }}">
                                            <i class="ri-{{ $api->is_active ? 'pause' : 'play' }}-line me-2"></i>
                                            {{ $api->is_active ? 'Desativar' : 'Ativar' }}
                                        </button>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li>
                                        <button wire:click="showDeleteConfirm({{ $api->id }})"
                                            class="text-danger dropdown-item">
                                            <i class="me-2 ri-delete-bin-line"></i> Remover
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted small">Status</span>
                                        <span class="badge {{ $api->is_active ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $api->is_active ? 'Ativa' : 'Inativa' }}
                                        </span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="text-muted small">Chave API</span>
                                        <span class="text-muted small">
                                            ****{{ substr($api->api_key, -4) }}
                                        </span>
                                    </div>
                                </div>
                                @if ($api->platform && $api->platform->supported_features)
                                    <div class="col-12">
                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                            @foreach ($api->platform->supported_features as $feature)
                                                <span class="bg-light text-dark badge small">
                                                    <i class="ri-{{ $this->getFeatureIcon($feature) }} me-1"></i>
                                                    {{ ucfirst($feature) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="text-muted card-footer small">
                            Criado em {{ $api->created_at->format('d/m/Y H:i') }}
                        </div>
                    </div>
                </div>
            @endforeach
        @else
            <!-- Estado vazio -->
            <div class="col-12">
                <div class="card custom-card">
                    <div class="py-5 text-center card-body">
                        <div class="mx-auto mb-3 avatar avatar-xl">
                            <div class="bg-light rounded-circle text-muted avatar-initial">
                                <i class="ri-key-line fs-1"></i>
                            </div>
                        </div>
                        <h5 class="mb-2">Nenhuma API configurada</h5>
                        <p class="mb-4 text-muted">Configure suas chaves de API para começar a usar os agentes de IA</p>
                        @if ($availablePlatforms->count() > 0)
                            <button wire:click="openModal" class="btn btn-primary">
                                <i class="me-2 ri-add-line"></i> Adicionar primeira API
                            </button>
                        @else
                            <div class="alert alert-info">
                                <i class="me-2 ri-information-line"></i>
                                Todas as plataformas disponíveis já estão configuradas.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Modal para adicionar API -->
    <div class="modal fade {{ $showModal ? 'show' : '' }}" style="{{ $showModal ? 'display: block;' : '' }}"
        tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="me-2 ri-add-line"></i>Adicionar Nova API
                    </h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>
                <form wire:submit.prevent="saveApi">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Plataforma</label>
                                <select wire:model="selectedPlatform" class="form-select">
                                    <option value="">Selecione uma plataforma...</option>
                                    @foreach ($availablePlatforms as $platform)
                                        <option value="{{ $platform->slug }}">
                                            {{ $platform->name }}
                                            @if ($platform->description)
                                                - {{ Str::limit($platform->description, 50) }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('selectedPlatform')
                                    <div class="mt-1 text-danger small">{{ $message }}</div>
                                @enderror
                            </div>

                            @if ($selectedPlatform)
                                <div class="col-12">
                                    <div class="bg-light card">
                                        <div class="card-body">
                                            @php
                                                $selectedPlatformData = $availablePlatforms->firstWhere(
                                                    'slug',
                                                    $selectedPlatform,
                                                );
                                            @endphp
                                            @if ($selectedPlatformData)
                                                <h6 class="card-title">{{ $selectedPlatformData->name }}</h6>
                                                <p class="mb-2 card-text small">
                                                    {{ $selectedPlatformData->description }}</p>

                                                @if ($selectedPlatformData->supported_features)
                                                    <div class="mb-2">
                                                        <small class="text-muted">Recursos suportados:</small>
                                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                                            @foreach ($selectedPlatformData->supported_features as $feature)
                                                                <span class="bg-primary badge small">
                                                                    <i
                                                                        class="ri-{{ $this->getFeatureIcon($feature) }} me-1"></i>
                                                                    {{ ucfirst($feature) }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                @if ($selectedPlatformData->api_key_required)
                                                    <div class="mb-0 alert alert-info small">
                                                        <i class="me-1 ri-information-line"></i>
                                                        Esta plataforma requer uma chave de API válida.
                                                        <a href="{{ $selectedPlatformData->documentation_url ?? '#' }}"
                                                            target="_blank" class="alert-link">
                                                            Ver documentação <i class="ri-external-link-line"></i>
                                                        </a>
                                                    </div>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="col-12">
                                <label class="form-label">Chave da API</label>
                                <div class="input-group">
                                    <input wire:model="apiKey" type="password" class="form-control"
                                        placeholder="Digite sua chave de API..." autocomplete="off">
                                    <button class="btn-outline-secondary btn" type="button"
                                        onclick="togglePassword(this)">
                                        <i class="ri-eye-line"></i>
                                    </button>
                                </div>
                                @error('apiKey')
                                    <div class="mt-1 text-danger small">{{ $message }}</div>
                                @enderror
                                <div class="form-text">
                                    Sua chave será criptografada e armazenada com segurança.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeModal">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="me-1 ri-save-line"></i> Salvar API
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Backdrop do modal -->
    @if ($showModal)
        <div class="modal-backdrop fade show"></div>
    @endif

    <script>
        function togglePassword(button) {
            const input = button.previousElementSibling;
            const icon = button.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'ri-eye-off-line';
            } else {
                input.type = 'password';
                icon.className = 'ri-eye-line';
            }
        }

        // Fechar modal com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && @js($showModal)) {
                @this.closeModal();
            }
        });
    </script>

    <style>
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1040;
        }

        .avatar-initial {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
    </style>

    @section('js')
        <!-- SweetAlert2 -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script>
            document.addEventListener('livewire:init', function() {
                Livewire.on('showDeleteApiConfirm', function(data) {
                    const id = data[0].id;
                    const platformName = data[0].platformName;

                    Swal.fire({
                        title: 'Remover API?',
                        text: `Tem certeza que deseja remover a API ${platformName}? Esta ação não pode ser desfeita.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sim, remover',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Livewire.dispatch('handleDeleteApi', [apiId = id]);
                        }
                    });
                });

                // Evento para mostrar resultado da remoção
                Livewire.on('apiDeleted', function(message) {
                    Swal.fire({
                        title: 'Sucesso!',
                        text: message,
                        icon: 'success',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    });
                });
            });
        </script>
    @endsection
</div>
