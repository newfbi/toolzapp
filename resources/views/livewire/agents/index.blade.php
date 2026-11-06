@php
    use App\Models\Agents;
@endphp
<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Painel de Agentes',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Agentes',
            ],
        ],
    ])
    <!-- Header com botão de criar -->
    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <div class="mb-4 col-12">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-1">Seus Agentes</h4>
                    <p class="mb-0 text-muted">Gerencie e configure seus agentes de IA</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    @php
                        // Verificar se o usuário tem plano ativo
                        $hasActivePlan = $user->mensalidade() !== 'vencido' && $user->plan;

                        // Se não tiver plano ativo, limitar a 1 agente
                        if (!$hasActivePlan) {
                            $agentLimit = 1;
                        } else {
                            $agentLimit = $user->plan->agentes ?? 0;
                        }

                        // Contar apenas agentes criados (excluindo rascunhos)
                        $currentAgents = Agents::where('user_id', $user->uuid)->where('status', '!=', 'draft')->count();

                        $hasReachedLimit = $currentAgents >= $agentLimit;
                    @endphp

                    @if ($hasReachedLimit)
                        <div class="me-3 text-end">
                            <div class="d-flex align-items-center gap-2">
                                <span class="bg-warning text-dark badge">
                                    <i class="me-1 ri-alert-line"></i>
                                    Limite atingido
                                </span>
                                <span class="text-muted small">
                                    {{ $currentAgents }}/{{ $agentLimit }} agentes
                                </span>
                            </div>
                            <small class="d-block text-muted">
                                @if (!$hasActivePlan)
                                    Você atingiu o limite de 1 agente sem plano ativo. Atualize seu plano para criar
                                    mais agentes.
                                @else
                                    Atualize seu plano para criar mais agentes
                                @endif
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn-outline-primary btn" disabled data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                title="{{ !$hasActivePlan ? 'Você atingiu o limite de 1 agente sem plano ativo. Atualize para criar mais.' : 'Você atingiu o limite de agentes do seu plano. Atualize para criar mais.' }}">
                                <i class="me-2 ri-user-add-line"></i>Criar Novo Agente
                            </button>
                            <a href="{{ route('planos.index') }}" class="btn btn-warning">
                                <i class="me-2 ri-vip-crown-line"></i>Upgrade Plano
                            </a>
                        </div>
                    @else
                        <a href="{{ route('agents.create') }}" class="btn btn-primary">
                            <i class="me-2 ri-user-add-line"></i>Criar Novo Agente
                        </a>
                        <div class="text-muted small">
                            @if (!$hasActivePlan)
                                Limite: 1 agente (sem plano ativo)
                            @else
                                Restam {{ $agentLimit - $currentAgents }} agentes disponíveis
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="card custom-card">
                <div class="row row-sm">
                    <div class="ps-0 pe-0 border-end col-xl-3 col-lg-6 col-sm-6">
                        <div class="text-center card-body">
                            <h6 class="mb-0">Total de Agentes</h6>
                            <h2 class="mt-2 mb-1 number-font">
                                <span class="counter">{{ $agents->count() ?? 0 }}</span>
                            </h2>
                            <p class="mb-0 text-muted">Agentes criados</p>
                        </div>
                    </div>
                    <div class="ps-0 pe-0 border-end col-xl-3 col-lg-6 col-sm-6">
                        <div class="text-center card-body">
                            <h6 class="mb-0">Total de Chats</h6>
                            <h2 class="mt-2 mb-1 number-font"><span
                                    class="counter">{{ $user->chats->count() ?? 0 }}</span>
                            </h2>
                            <p class="mb-0 text-muted">Interação com os Agentes</p>
                        </div>
                    </div>
                    <div class="ps-0 pe-0 border-end col-xl-3 col-lg-6 col-sm-6">
                        <div class="text-center card-body">
                            <h6 class="mb-0">Total de Mensagens</h6>
                            <h2 class="mt-2 mb-1 number-font"><span
                                    class="counter">{{ $user->messages->count() ?? 0 }}</span></h2>
                            <p class="mb-0 text-muted">Conversas totais dos Agentes</p>
                        </div>
                    </div>
                    <div class="ps-0 pe-0 col-xl-3 col-lg-6 col-sm-6">
                        <div class="text-center card-body">
                            <h6 class="mb-0">Total de Conversões</h6>
                            <h2 class="mt-2 mb-1 number-font"><span
                                    class="counter">{{ $user->convertedChats->count() ?? 0 }}</span></h2>
                            <p class="mb-0 text-muted">Envios de Arquivos</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row end -->
    <!--Row-->
    <div class="row row-sm">
        @foreach ($agents as $agent)
            <div class="col-sm-3 col-md-3 col-xl-3">
                <div
                    class="position-relative d-flex flex-wrap align-items-center p-3 border rounded {{ $agent->status === 'disabled' ? 'opacity-75 border-secondary' : '' }}">
                    <div class="d-flex align-items-center gap-3 mx-auto py-3 w-auto">
                        <div class="d-flex flex-column align-items-center gap-2">
                            <div class="position-relative">
                                <img src="{{ $agent->avatar ? '/assets/img/' . $agent->avatar : '/assets/images/avatar/default.png' }}"
                                    alt="Avatar"
                                    class="rounded-circle {{ $agent->status === 'disabled' ? 'opacity-50' : '' }}"
                                    style="height: 64px; width: 64px; object-fit: cover;">
                                @if ($agent->status == 'enabled')
                                    <div class="bottom-0 position-absolute end-0">
                                        <svg style="width: 20px; height: 20px;">
                                            <use href="/assets/svg/icon-sprite.svg#profile-check"></use>
                                        </svg>
                                    </div>
                                @else
                                    <div class="bottom-0 position-absolute end-0">
                                        <i class="text-secondary ri-pause-circle-fill" style="font-size: 20px;"></i>
                                    </div>
                                @endif
                            </div>
                            <div class="text-center">
                                <h6 class="mb-0 {{ $agent->status === 'disabled' ? 'text-muted' : '' }}">
                                    {{ $agent->name }}</h6>
                                <p class="mb-0 text-muted small">{{ $agent->expertise }}</p>
                                <p class="mb-1 small">
                                    <strong>Chats:</strong> {{ $agent->chats->count() }} |
                                    <strong>Mensagens:</strong> {{ $agent->messages->count() }}
                                </p>

                                @php
                                    // Verificar se este agente específico está em processamento
                                    // Checando se os campos contêm os placeholders de erro
                                    $isProcessing =
                                        str_contains($agent->training ?? '', 'não foi gerado automaticamente') ||
                                        str_contains($agent->instructions ?? '', 'não foi gerado automaticamente') ||
                                        str_contains($agent->faq ?? '', 'não foi gerado automaticamente') ||
                                        str_contains($agent->closing ?? '', 'não foi gerado automaticamente');
                                @endphp

                                <p class="mb-2">
                                    <strong>Status:</strong>
                                    @if ($isProcessing)
                                        <span class="badge bg-info text-white">
                                            <i class="ri-loader-4-line me-1 agent-processing-spinner"></i>Processando
                                            Treinamento
                                        </span>
                                        <br><small class="d-block mt-1 text-muted">Gerando prompts em
                                            background...</small>
                                    @elseif ($agent->status == 'enabled')
                                        <span class="badge bg-success"><i class="me-1 ri-play-fill"></i>Ativado</span>
                                    @else
                                        <span class="badge bg-secondary"><i
                                                class="me-1 ri-pause-fill"></i>Desativado</span>
                                        <br><small class="d-block mt-1 text-muted">Não responde mensagens</small>
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>

                    @if ($agent->status === 'disabled')
                        <div
                            class="top-0 position-absolute d-flex align-items-center justify-content-center bg-light opacity-25 rounded w-100 h-100 start-0">
                            <div class="text-center">
                                <i class="text-secondary ri-pause-circle-line" style="font-size: 24px;"></i>
                            </div>
                        </div>
                    @endif

                    <div class="top-0 position-absolute m-2 end-0">
                        <div class="dropdown">
                            <a href="javascript:void(0)" class="btn btn-light icon-btn" data-bs-toggle="dropdown"
                                aria-expanded="false"> <i class="bi bi-three-dots-vertical"></i></a>
                            <div class="dropdown-menu dropdown-menu-end" style="">
                                <a class="dropdown-item" href="{{ route('agents.edit', $agent->uuid) }}">Editar
                                    Agente</a>
                                <a class="dropdown-item" href="javascript:void(0)"
                                    wire:click="copyAgent('{{ $agent->uuid }}')">Duplicar Agente</a>
                                <div class="dropdown-divider"></div>
                                <button
                                    class="dropdown-item {{ $agent->status === 'enabled' ? 'text-warning' : 'text-success' }}"
                                    wire:click="showToggleConfirm('{{ $agent->uuid }}', '{{ $agent->name }}', '{{ $agent->status }}')">
                                    <i class="ri-{{ $agent->status === 'enabled' ? 'pause' : 'play' }}-line me-2"></i>
                                    {{ $agent->status === 'enabled' ? 'Desativar' : 'Ativar' }} Agente
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <!-- Row end -->
</div>


@section('js')
    <style>
        /* Animação de rotação para o ícone de processamento */
        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .agent-processing-spinner {
            display: inline-block;
            animation: spin 1s linear infinite;
        }
    </style>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Inicializar tooltips do Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>

    <script>
        document.addEventListener('livewire:init', function() {
            Livewire.on('showToggleAgentConfirm', function(data) {
                const id = data[0].id;
                const name = data[0].name;
                const currentStatus = data[0].currentStatus;
                const action = data[0].action;

                const actionText = action === 'desativar' ? 'desativar' : 'ativar';
                const confirmText = action === 'desativar' ? 'Sim, desativar' : 'Sim, ativar';
                const iconColor = action === 'desativar' ? '#dc3545' : '#28a745';

                Swal.fire({
                    title: `${actionText.charAt(0).toUpperCase() + actionText.slice(1)} Agente?`,
                    text: `Tem certeza que deseja ${action} o agente "${name}"?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: confirmText,
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: iconColor,
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch('toggleAgentStatus', [id]);
                    }
                });
            });

            // Evento para mostrar resultado da alteração de status
            Livewire.on('agentStatusToggled', function(message) {
                Swal.fire({
                    title: 'Sucesso!',
                    text: message,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                });
            });

            // Verificação de status de geração de prompts (polling)
            let trainingStatusCheckInterval = null;
            let lastCheckTime = Date.now();

            // Iniciar verificação se houver indicação de processamento em andamento
            function startTrainingStatusCheck() {
                // Limpar intervalo anterior se existir
                if (trainingStatusCheckInterval) {
                    clearInterval(trainingStatusCheckInterval);
                }

                // Verificar a cada 5 segundos
                trainingStatusCheckInterval = setInterval(function() {
                    @this.call('checkAgentsTrainingStatus').then(function(result) {
                        const data = result[0] || result;

                        // Se não há jobs pendentes mas houve atualização recente, recarregar a lista
                        if (data.should_refresh) {
                            // Recarregar o componente Livewire para atualizar a lista
                            @this.call('$refresh');

                            // Limpar o intervalo após atualizar
                            if (trainingStatusCheckInterval) {
                                clearInterval(trainingStatusCheckInterval);
                                trainingStatusCheckInterval = null;
                            }
                        }

                        // Se não há jobs pendentes e não houve atualização recente, parar verificação
                        if (!data.has_pending_jobs && data.recently_updated_count === 0) {
                            if (trainingStatusCheckInterval) {
                                clearInterval(trainingStatusCheckInterval);
                                trainingStatusCheckInterval = null;
                            }
                        }
                    });
                }, 5000); // Verificar a cada 5 segundos
            }

            // Verificar ao montar o componente se há processamento em andamento
            // (pode ter sido iniciado na página anterior)
            setTimeout(function() {
                @this.call('checkAgentsTrainingStatus').then(function(result) {
                    const data = result[0] || result;
                    if (data.has_pending_jobs) {
                        startTrainingStatusCheck();
                    }
                });
            }, 2000); // Aguardar 2 segundos após carregar a página
        });
    </script>

    <!-- App js -->
    <script src="/assets/js/app.js"></script>
@endsection
