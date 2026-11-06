<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Instâncias WhatsApp',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'WhatsApp',
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
                    <p class="mb-0 text-muted">Gerencie suas instâncias de WhatsApp para integração com agentes</p>
                    @php
                        $hasActivePlan = $this->user->plan && $this->user->mensalidade() !== 'vencido';
                        $hasAnyInstance = count($instances) > 0;
                        $currentInstancesCount = 0;
                        $maxInstances = 0;

                        if ($hasActivePlan) {
                            $currentInstancesCount = \App\Models\WhatsappInstance::where('user_id', $this->user->uuid)
                                ->where('is_trial', false)
                                ->count();
                            $maxInstances = $this->user->plan->instances ?? 0;
                        } else {
                            $currentInstancesCount = \App\Models\WhatsappInstance::where('user_id', $this->user->uuid)
                                ->where('is_trial', true)
                                ->where(function ($query) {
                                    $query->whereNull('trial_expires_at')->orWhere('trial_expires_at', '>', now());
                                })
                                ->count();
                            $maxInstances = 1; // Limite de teste
                        }

                        $canCreateMore = $currentInstancesCount < $maxInstances;
                    @endphp

                    @if (!$hasActivePlan && !$hasAnyInstance)
                        <div class="mt-2 alert alert-info small">
                            <i class="me-1 ri-information-line"></i>
                            <strong>Modo Teste:</strong> Você pode criar uma instância de teste com 48 horas de
                            validade. Para funcionalidades completas, assine um plano.
                        </div>
                    @elseif ($hasActivePlan && !$canCreateMore)
                        <div class="mt-2 alert alert-warning small">
                            <i class="me-1 ri-alert-line"></i>
                            <strong>Limite Atingido:</strong> Você criou {{ $currentInstancesCount }} de
                            {{ $maxInstances }} instâncias permitidas.
                            <a href="{{ route('planos.index') }}" class="alert-link">Faça upgrade do plano</a> para criar
                            mais instâncias.
                        </div>
                    @elseif (!$hasActivePlan && !$canCreateMore)
                        <div class="mt-2 alert alert-warning small">
                            <i class="me-1 ri-alert-line"></i>
                            <strong>Limite de Teste Atingido:</strong> Você já possui uma instância de teste ativa.
                            <a href="{{ route('planos.index') }}" class="alert-link">Assine um plano</a> para criar mais
                            instâncias.
                        </div>
                    @elseif ($hasActivePlan && $canCreateMore)
                        <div class="mt-2 alert alert-success small">
                            <i class="me-1 ri-check-line"></i>
                            Você criou {{ $currentInstancesCount }} de {{ $maxInstances }} instâncias permitidas.
                        </div>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    @if (!$hasActivePlan || !$canCreateMore)
                        <a href="{{ route('planos.index') }}" class="btn-outline-primary btn">
                            <i class="me-2 ri-crown-line"></i>Ver Planos
                        </a>
                    @endif
                    <button type="button" class="btn btn-primary" wire:click="openCreateModal"
                        @if (!$canCreateMore) disabled @endif>
                        <i class="me-2 ri-add-line"></i>Nova Instância
                    </button>
                </div>
            </div>
        </div>

        <!-- Instances List -->
        @forelse($instances as $instance)
            <div class="mb-4 col-md-4 col-lg-3">
                <div
                    class="card h-100 {{ $instance['status'] === 'connected' ? 'border-success' : ($instance['status'] === 'error' ? 'border-danger' : 'border-secondary') }} {{ $instance['is_trial'] ? 'border-warning' : '' }}">
                    <div class="d-flex align-items-center justify-content-between card-header">
                        <div class="d-flex align-items-center">
                            <h6 class="me-2 mb-0">Nome:
                                {{ $instance['name'] ?? 'Instância ' . $instance['instance_id'] }}</h6>
                            @if ($instance['is_trial'])
                                <span class="bg-warning text-dark badge">
                                    <i class="me-1 ri-time-line"></i>Teste
                                </span>
                            @endif
                        </div>
                        <div class="dropdown">
                            <button class="btn-outline-secondary btn btn-sm" type="button" data-bs-toggle="dropdown">
                                <i class="ri-more-2-fill"></i>
                            </button>
                            <ul class="dropdown-menu">
                                @php
                                    $isTrialExpired =
                                        $instance['is_trial'] &&
                                        \Carbon\Carbon::parse($instance['trial_expires_at'])->isPast();
                                @endphp

                                @if ($instance['status'] === 'disconnected' || $instance['status'] === 'corrupted')
                                    @if ($isTrialExpired)
                                        <li><span class="text-muted dropdown-item">
                                                <i class="me-2 ri-lock-line"></i>Teste Expirado
                                            </span></li>
                                    @else
                                        <li><a class="dropdown-item" href="#"
                                                wire:click="connectInstance('{{ $instance['uuid'] }}')">
                                                <i class="me-2 ri-play-circle-line"></i>
                                                @if ($instance['status'] === 'corrupted')
                                                    Reconectar (Corrompida)
                                                @elseif (
                                                    $instance['error_message'] &&
                                                        (str_contains(is_array($instance['error_message']) ? json_encode($instance['error_message']) : $instance['error_message'],
                                                            'Sessão expirada') ||
                                                            str_contains(is_array($instance['error_message'])
                                                                    ? json_encode($instance['error_message'])
                                                                    : $instance['error_message'],
                                                                'novo QR code')))
                                                    Novo QR Code
                                                @else
                                                    Conectar
                                                @endif
                                            </a></li>
                                    @endif
                                @elseif($instance['status'] === 'connected')
                                    <li><a class="text-warning dropdown-item" href="#"
                                            wire:click="disconnectInstance('{{ $instance['uuid'] }}')">
                                            <i class="me-2 ri-pause-circle-line"></i>Desconectar
                                        </a></li>
                                    @if ($isTrialExpired)
                                        <li><span class="text-muted dropdown-item">
                                                <i class="me-2 ri-lock-line"></i>Reinício Indisponível
                                            </span></li>
                                    @else
                                        <li><a class="text-info dropdown-item" href="#"
                                                wire:click="restartInstance('{{ $instance['uuid'] }}')">
                                                <i class="me-2 ri-refresh-line"></i>Reiniciar
                                            </a></li>
                                    @endif
                                @elseif($instance['status'] === 'connecting')
                                    <li><span class="text-muted dropdown-item">
                                            <i class="me-2 ri-loader-4-line"></i>Conectando...
                                        </span></li>
                                    {{-- 🆕 Permitir reabrir modal se usuário fechou por engano --}}
                                    <li><a class="dropdown-item" href="#"
                                            wire:click="connectInstance('{{ $instance['uuid'] }}')">
                                            <i class="me-2 ri-qr-code-line"></i>Ver QR Code
                                        </a></li>
                                @endif
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="text-danger dropdown-item" href="#"
                                        wire:click="showDeleteConfirm('{{ $instance['uuid'] }}', '{{ $instance['name'] ?? 'Instância ' . $instance['instance_id'] }}')">
                                        <i class="me-2 ri-delete-bin-line"></i>Excluir
                                    </a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <small class="text-muted">ID:</small>
                            <code class="ms-1">{{ $instance['instance_id'] }}</code>
                        </div>

                        @if ($instance['phone_number'])
                            <div class="mb-2">
                                <small class="text-muted">Número:</small>
                                <span class="ms-1">{{ $instance['phone_number'] }}</span>
                            </div>
                        @endif

                        @if ($instance['agent'])
                            <div class="mb-2">
                                <small class="text-muted">Agente:</small>
                                <span class="ms-1">{{ $instance['agent']['name'] ?? 'N/A' }}</span>
                            </div>
                        @endif

                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                @switch($instance['status'])
                                    @case('connected')
                                        <span class="bg-success badge">
                                            <i class="me-1 ri-check-line"></i>Conectado
                                        </span>
                                    @break

                                    @case('connecting')
                                        <span class="bg-warning badge">
                                            <i class="me-1 ri-loader-4-line"></i>Conectando
                                        </span>
                                    @break

                                    @case('disconnected')
                                        <span class="bg-secondary badge">
                                            <i class="me-1 ri-pause-circle-line"></i>Desconectado
                                        </span>
                                    @break

                                    @case('error')
                                        <span class="bg-danger badge">
                                            <i class="me-1 ri-error-warning-line"></i>Erro
                                        </span>
                                    @break

                                    @case('corrupted')
                                        <span class="bg-dark badge">
                                            <i class="me-1 ri-bug-line"></i>Corrompida
                                        </span>
                                    @break

                                    @case('banned')
                                        <span class="bg-danger badge">
                                            <i class="me-1 ri-forbid-line"></i>Banido
                                        </span>
                                    @break

                                    @case('expired')
                                        <span class="bg-danger badge">
                                            <i class="me-1 ri-time-line"></i>Expirada
                                        </span>
                                    @break
                                @endswitch
                            </div>
                        </div>

                        {{-- Informações da instância de teste --}}
                        @if ($instance['is_trial'])
                            <div class="mt-2">
                                @php
                                    $trialExpiresAt = \Carbon\Carbon::parse($instance['trial_expires_at']);
                                    $isExpired = $trialExpiresAt->isPast();
                                    $hoursRemaining = $isExpired ? 0 : now()->diffInHours($trialExpiresAt, false);
                                @endphp

                                @if ($isExpired)
                                    <div class="mb-0 alert alert-danger small">
                                        <i class="me-1 ri-error-warning-line"></i>
                                        <strong>Teste Expirado</strong> - Para continuar usando, assine um plano.
                                    </div>
                                @else
                                    <div class="mb-0 alert alert-warning small">
                                        <i class="me-1 ri-information-line"></i>
                                        <strong>Modo Teste:</strong> Esta instância de teste é válida apenas por 48
                                        horas. Para funcionalidades completas, assine um plano.
                                        <br><small class="text-muted">
                                            <i class="me-1 ri-time-line"></i>
                                            <strong>Tempo Restante:</strong>
                                            @if ($hoursRemaining > 24)
                                                {{ floor($hoursRemaining / 24) }} dia(s) e
                                                {{ number_format($hoursRemaining % 24, 2) }}
                                                hora(s)
                                            @else
                                                {{ number_format($hoursRemaining, 2) }} hora(s)
                                            @endif
                                        </small>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Botão para gerenciar chat quando conectado --}}
                        @if ($instance['status'] === 'connected' && $instance['agent'])
                            @php
                                $isTrialExpired =
                                    $instance['is_trial'] &&
                                    \Carbon\Carbon::parse($instance['trial_expires_at'])->isPast();
                            @endphp

                            <div class="mt-3">
                                @if ($isTrialExpired)
                                    <button class="w-100 btn btn-secondary btn-sm" disabled>
                                        <i class="me-1 ri-lock-line"></i>Gerenciar Chat (Teste Expirado)
                                    </button>
                                @else
                                    <a href="{{ route('agents.manage', $instance['agent']['uuid']) }}"
                                        class="w-100 btn btn-success btn-sm">
                                        <i class="me-1 ri-message-3-line"></i>Gerenciar Chat
                                    </a>
                                @endif
                            </div>
                        @endif

                        {{-- 🆕 Botões quando conectando --}}
                        @if ($instance['status'] === 'connecting' && $instance['agent'])
                            <div class="mt-3">
                                {{-- Botão para reabrir modal do QR --}}
                                <button class="w-100 btn btn-primary btn-sm mb-2"
                                    wire:click="connectInstance('{{ $instance['uuid'] }}')">
                                    <i class="me-1 ri-qr-code-line"></i>Ver QR Code
                                </button>

                                {{-- Botão de refresh rápido --}}
                                <button class="w-100 btn btn-outline-primary btn-sm"
                                    @if (($instance['provider'] ?? 'baileys') === 'zapi')
                                        wire:click="refreshZapiQrCode('{{ $instance['uuid'] }}')"
                                    @else
                                        onclick="refreshQRCode('{{ $instance['agent']['uuid'] }}')"
                                    @endif>
                                    <i class="me-1 ri-refresh-line"></i>Atualizar QR Code
                                </button>
                                <small class="text-muted d-block mt-1 text-center">
                                    <i class="me-1 ri-information-line"></i>QR expirando? Clique para gerar novo
                                </small>
                            </div>
                        @endif

                        <div class="mt-2">
                            @if ($instance['last_activity'])
                                <small class="text-muted">
                                    Última atividade:
                                    {{ \Carbon\Carbon::parse($instance['last_activity'])->diffForHumans() }}
                                </small>
                            @endif
                        </div>

                        @if ($instance['error_message'])
                            <div class="mt-2 px-2 py-1 alert alert-danger small">
                                <i
                                    class="me-1 ri-error-warning-line"></i>{{ is_array($instance['error_message']) ? json_encode($instance['error_message']) : $instance['error_message'] }}
                                @php
                                    $errorMessage = is_array($instance['error_message'])
                                        ? json_encode($instance['error_message'])
                                        : $instance['error_message'];
                                @endphp
                                @if (str_contains($errorMessage, 'terminada pelo WhatsApp') ||
                                        str_contains($errorMessage, 'desconexão de outro dispositivo'))
                                    <div class="mt-2">
                                        <small class="text-warning">
                                            <i class="me-1 ri-alert-line"></i>
                                            <strong>Atenção:</strong> Sua conta pode ter sido desconectada de outro
                                            dispositivo ou o WhatsApp detectou atividade suspeita.
                                        </small>
                                    </div>
                                @elseif (str_contains($errorMessage, 'Sessão expirada') || str_contains($errorMessage, 'novo QR code'))
                                    <div class="mt-2">
                                        <small class="text-muted">Clique em "Novo QR Code" para gerar um novo
                                            código.</small>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            @empty
                <div class="col-12">
                    <div class="card">
                        <div class="py-5 text-center card-body">
                            <i class="mb-3 text-muted ri-whatsapp-line display-4"></i>
                            <h5>Nenhuma instância encontrada</h5>
                            <p class="text-muted">Crie sua primeira instância do WhatsApp para começar a usar.</p>
                            <button type="button" class="btn btn-primary" wire:click="openCreateModal">
                                <i class="me-2 ri-add-line"></i>Criar Primeira Instância
                            </button>
                        </div>
                    </div>
                </div>
            @endforelse

            <!-- Create Instance Modal -->
            <div class="modal fade" id="createInstanceModal" tabindex="-1" wire:ignore.self>
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Nova Instância WhatsApp</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form wire:submit="createInstance">

                                <div class="mb-3">
                                    <label class="form-label">Nome da instancia*</label>
                                    <input type="text" class="form-control" wire:model="name"
                                        placeholder="Nome descritivo para a instância" required>
                                    @error('name')
                                        <div class="text-danger small">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Seleção do tipo de agente para instância -->
                                <div class="mb-3">
                                    <label class="form-label">Tipo de Agente</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" id="agentTypeExisting"
                                            wire:model.live="agentType" value="existing" autocomplete="off">
                                        <label class="btn-outline-primary btn" for="agentTypeExisting">
                                            <i class="me-2 ri-user-line"></i>Usar Agente Existente
                                        </label>

                                        <input type="radio" class="btn-check" id="agentTypeModel"
                                            wire:model.live="agentType" value="model" autocomplete="off">
                                        <label class="btn-outline-success btn" for="agentTypeModel">
                                            <i class="me-2 ri-robot-line"></i>Usar Modelo de API
                                        </label>
                                    </div>
                                </div>

                                @if ($agentType === 'existing')
                                    <!-- Seleção de Agente Existente -->
                                    @if (empty($agents) || count($agents) === 0)
                                        <div class="alert alert-warning">
                                            <i class="me-2 ri-alert-line"></i>
                                            Você não possui agentes disponíveis. Crie um agente primeiro.
                                        </div>
                                    @else
                                        <div class="mb-3">
                                            <label for="selectedAgentId" class="form-label">Selecione o Agente <span
                                                    class="text-danger">*</span></label>
                                            <select class="form-select @error('agent_id') is-invalid @enderror"
                                                id="selectedAgentId" wire:model.live="agent_id" required>
                                                <option value="">-- Selecione um agente --</option>
                                                @foreach ($agents as $agent)
                                                    <option value="{{ $agent->uuid }}">{{ $agent->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('agent_id')
                                                <div class="d-block invalid-feedback">{{ $message }}</div>
                                            @enderror
                                            @if ($agent_id)
                                                <div class="text-success form-text">
                                                    <i class="me-1 ri-check-line"></i> Agente selecionado
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                @elseif ($agentType === 'model')
                                    <!-- Seleção de Modelo de API -->
                                    @if (empty(\App\Support\AiModelCatalog::all()))
                                        <div class="alert alert-warning">
                                            <i class="me-2 ri-alert-line"></i>
                                            Nenhum modelo de conversação está configurado.
                                        </div>
                                    @else
                                        <div class="mb-3">
                                            <label for="selectedModel" class="form-label">Selecione o Modelo <span
                                                    class="text-danger">*</span></label>
                                            <select class="form-select @error('selectedModel') is-invalid @enderror"
                                                id="selectedModel" wire:model.live="selectedModel" required>
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

                                <div class="mb-3">
                                    <label class="form-label">Descrição (opcional)</label>
                                    <textarea class="form-control" wire:model="description" rows="3" placeholder="Descrição da instância"></textarea>
                                    @error('description')
                                        <div class="text-danger small">{{ $message }}</div>
                                    @enderror
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" wire:click="createInstance">Criar
                                Instância</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QR Code Modal -->
            @if ($showQrModal && $selectedInstance)
                <div class="modal fade {{ $showQrModal ? 'show d-block' : '' }}" id="qrCodeModal" tabindex="-1"
                    style="{{ $showQrModal ? 'background-color: rgba(0,0,0,0.5);' : '' }}" wire:ignore.self>
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="me-2 ri-whatsapp-line"></i>Conectar WhatsApp
                                </h5>
                                <button type="button" class="btn-close" wire:click="closeQrModal"></button>
                            </div>
                            <div class="text-center modal-body">
                                <div class="mb-3">
                                    <h6>{{ $selectedInstance['name'] ?? 'Instância ' . ($selectedInstance['instance_id'] ?? 'N/A') }}
                                    </h6>

                                    @if ($connectingInstance === $selectedInstance['uuid'] && $selectedInstance['status'] === 'connecting')
                                        <p class="text-warning">
                                            <i class="me-2 ri-time-line"></i>Conectando...
                                        </p>
                                    @else
                                        <p class="text-muted">Escaneie o QR Code com o WhatsApp no seu celular</p>
                                    @endif
                                </div>

                                @if (
                                    $connectingInstance === $selectedInstance['uuid'] &&
                                        $selectedInstance['status'] === 'connecting' &&
                                        !$qrCodeData &&
                                        !$persistentQrCode)
                                    <!-- Estado: Conectando (QR lido, aguardando conexão) -->
                                    <div class="mb-3">
                                        <div class="spinner-border text-success" role="status"
                                            style="width: 3rem; height: 3rem;">
                                            <span class="visually-hidden">Conectando...</span>
                                        </div>
                                        <h5 class="mt-3 text-success">QR Code lido com sucesso!</h5>
                                        <p class="text-muted">Estabelecendo conexão com WhatsApp...</p>
                                    </div>
                                @elseif ($qrCodeData || $persistentQrCode)
                                    <!-- Estado: QR disponível -->
                                    @php
                                        // ✅ SIMPLIFICADO: Função para extrair QR code de forma segura
                                        function extractQrForDisplay($qrData)
                                        {
                                            if (empty($qrData)) {
                                                return null;
                                            }

                                            // ✅ Se for array, extrair apenas 'qr' ou primeiro elemento
                                            if (is_array($qrData)) {
                                                if (isset($qrData['qr']) && is_string($qrData['qr'])) {
                                                    $qrData = $qrData['qr'];
                                                } elseif (isset($qrData[0]) && is_string($qrData[0])) {
                                                    $qrData = $qrData[0];
                                                } else {
                                                    return null;
                                                }
                                            }

                                            // ✅ Se for objeto, converter para array
                                            if (is_object($qrData)) {
                                                $qrArray = (array) $qrData;
                                                if (isset($qrArray['qr']) && is_string($qrArray['qr'])) {
                                                    $qrData = $qrArray['qr'];
                                                } else {
                                                    return null;
                                                }
                                            }

                                            // ✅ Agora deve ser string
                                            if (!is_string($qrData) || empty($qrData)) {
                                                return null;
                                            }

                                            // ✅ Se já é data URL completa, retornar como está
                                            if (strpos($qrData, 'data:image') === 0) {
                                                return $qrData;
                                            }

                                            // ✅ Se é base64 puro, adicionar prefixo data:image
                                            // Validar se parece ser base64 válido
                                            if (strlen($qrData) > 20 && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $qrData)) {
                                                return 'data:image/png;base64,' . $qrData;
                                            }

                                            return null;
                                        }

                                        // Extrair QR code para exibição
                                        $qrToDisplay =
                                            extractQrForDisplay($qrCodeData) ?: extractQrForDisplay($persistentQrCode);
                                    @endphp
                                    @if ($qrToDisplay && is_string($qrToDisplay))
                                        <div class="mb-3" id="qrCodeContainer">
                                            <img src="{{ $qrToDisplay }}" alt="QR Code" class="rounded img-fluid"
                                                style="max-width: 300px;" id="qrCodeImage">
                                        </div>
                                    @else
                                        <div class="mb-3" id="qrCodeLoading">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Gerando QR Code...</span>
                                            </div>
                                            <p class="mt-2 text-muted">Gerando QR Code...</p>
                                        </div>
                                    @endif

                                    <div class="alert alert-info">
                                        <i class="me-2 ri-information-line"></i>
                                        <strong>Instruções:</strong><br>
                                        1. Abra o WhatsApp no seu celular<br>
                                        2. Toque em "Menu" (⋮) > "WhatsApp Web"<br>
                                        3. Escaneie o código acima<br>
                                        4. Aguarde a conexão ser estabelecida
                                    </div>
                                @else
                                    <!-- Estado: Gerando QR -->
                                    <div class="mb-3" id="qrCodeLoading">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Gerando QR Code...</span>
                                        </div>
                                        <p class="mt-2 text-muted">Gerando QR Code...</p>
                                    </div>
                                @endif

                                <div class="d-flex justify-content-center">
                                    <button class="me-2 btn-outline-primary btn"
                                        @if (($selectedInstance['provider'] ?? 'baileys') === 'zapi')
                                            wire:click="refreshZapiQrCode('{{ $selectedInstance['uuid'] }}')"
                                        @else
                                            onclick="refreshQRCode('{{ $selectedInstance['agent']['uuid'] ?? '' }}')"
                                        @endif>
                                        <i class="me-1 ri-refresh-line"></i>Atualizar QR
                                    </button>
                                    <button class="btn-outline-secondary btn" wire:click="closeQrModal">
                                        Fechar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div> <!-- End Row -->
    </div> <!-- End Container Fluid -->

    @section('js')
        <!-- SweetAlert2 -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Socket.io Client -->
        <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>

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
            // ✅ userId real do usuário autenticado (usado em reconexões do socket)
            window.currentUserId = @json($this->user->uuid);
            // ✅ Banner de servidor offline já exibido nesta sessão de página
            let whatsappServerOfflineNotified = false;

            let socket = null;
            let socketInitialized = false;
            let fatalSessionAgents = new Set(); // agentIds com erro fatal
            window.instanceAgentMap = window.instanceAgentMap || {};
            window.statusCheckIntervals = window.statusCheckIntervals || {};
            window.statusQueries = window.statusQueries || new Set(); // Controle de consultas em andamento

            function initSocket() {
                if (socketInitialized) {
                    return;
                }

                socketInitialized = true;

                socket = io(SOCKET_URL, {
                    auth: { token: SOCKET_AUTH_TOKEN },
                    transports: ['websocket', 'polling'],
                    reconnection: true,
                    reconnectionAttempts: 3,
                    reconnectionDelay: 1500,
                    autoConnect: true
                });

                socket.on('connect', () => {
                    // ✅ Socket conectado com sucesso (silencioso)
                    window.instanceAgentMap = window.instanceAgentMap || {};

                    // Após reconexão, verificar instâncias ativas críticas
                    Object.keys(window.instanceAgentMap).forEach(agentId => {
                        const instanceId = window.instanceAgentMap[agentId];

                        // Aguardar um pouco para estabilizar
                        setTimeout(() => {
                            Livewire.dispatch('connectViaSocket', {
                                agentId: agentId,
                                instanceId: instanceId,
                                userId: window.currentUserId
                            });
                        }, 1000);
                    });
                });

                socket.on('connect_error', (err) => {
                    console.error('❌ Erro Socket.IO:', err.message);

                    // 🚨 Avisa uma única vez que o servidor Baileys está inacessível
                    if (!whatsappServerOfflineNotified) {
                        whatsappServerOfflineNotified = true;
                        Swal.fire({
                            title: 'Servidor WhatsApp indisponível',
                            text: `Não foi possível conectar ao servidor Baileys em ${SOCKET_URL}. Verifique se o serviço está rodando (npm run whatsapp:start).`,
                            icon: 'warning',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#f0ad4e'
                        });
                    }
                });

                socket.on('disconnect', (reason) => {
                    // 🔌 Socket desconectado (silencioso)
                });

                socket.on('status', (data) => {
                    // 🚨 ERRO FATAL — BLOQUEIA RECONEXÃO DA INSTÂNCIA
                    if (data.requires_new_qr && data.agent_id) {
                        fatalSessionAgents.add(data.agent_id);
                        console.warn(`🚫 Instância ${data.agent_id} marcada como erro fatal`);
                    }

                    handleStatusUpdate(data);
                });

                // 🆕 Listener para resposta de consulta de status
                socket.on('session-status', (data) => {
                    // Liberar consulta pendente
                    if (data.agent_id) {
                        window.statusQueries.delete(data.agent_id);
                    }

                    // Atualizar status local
                    window.currentInstanceStatus = window.currentInstanceStatus || {};
                    window.currentInstanceStatus[data.agent_id] = {
                        status: data.status,
                        timestamp: Date.now()
                    };

                    // Repassar para o handler normal
                    handleStatusUpdate(data);
                });

                // 🆕 Listener do evento 'qrcode' emitido pelo servidor Baileys
                // Renderiza o QR imediatamente sem esperar a rodada Livewire do evento 'status'
                socket.on('qrcode', (data) => {
                    if (!data?.agent_id || !data?.url) return;

                    handleStatusUpdate({
                        agent_id: data.agent_id,
                        status: 'qr',
                        qrCode: data.url
                    });
                });

                // 🆕 Listener da resposta do polling 'check-instance-status'
                socket.on('instance-status', (data) => {
                    handleStatusUpdate(data);
                });
            }

            initSocket();

            /**
             * 🔌 Conecta UMA instância (room) no socket já existente
             */
            function socketConnectInstance(config) {
                initSocket();

                if (!socket || !socket.connected) {
                    console.error('❌ Socket não conectado');
                    return;
                }

                if (fatalSessionAgents.has(config.agentId)) {
                    // ✅ Ação explícita do usuário (force=true vindo do Livewire) libera o bloqueio
                    if (config.force) {
                        console.log(`🔓 [${config.agentId}] Reconexão manual autorizada - liberando bloqueio de erro fatal`);
                        fatalSessionAgents.delete(config.agentId);
                    } else {
                        console.warn(`🚫 Instância ${config.agentId} bloqueada (erro fatal)`);
                        return;
                    }
                }

                // 🔒 Controle de consultas simultâneas
                if (window.statusQueries.has(config.agentId)) {
                    return;
                }

                window.statusQueries.add(config.agentId);

                // Mapear agentId -> instanceId
                window.instanceAgentMap[config.agentId] = config.instanceId;

                // 🆕 Primeiro: consultar status atual (não inicializar)
                socket.emit('get-session-status', {
                    agentId: config.agentId
                });

                // Se não receber resposta em 2s, então inicializar
                setTimeout(() => {
                    window.statusQueries.delete(config.agentId); // Liberar consulta

                    const currentStatus = window.currentInstanceStatus?.[config.agentId];

                    // ✅ Sempre tentar inicializar quando:
                    // - Não há status (primeira vez)
                    // - Status é 'disconnected' (desconectado)
                    // - Status é 'connected' (reconexão após restart do servidor)
                    // - Status é 'connecting' (usuário clicou em conectar)
                    // - Status é 'qr' (🆕 CORRIGIDO: QR code gerado, permitir reconexão)
                    if (!currentStatus ||
                        currentStatus.status === 'disconnected' ||
                        currentStatus.status === 'connected' ||
                        currentStatus.status === 'connecting' ||
                        currentStatus.status === 'qr') {

                        console.log(
                            `🚀 Inicializando sessão para ${config.agentId} (status: ${currentStatus?.status || 'unknown'})`
                        );

                        socket.emit('initialize', {
                            userId: config.userId,
                            agentId: config.agentId
                        });
                    } else {
                        console.log(`⏸️ Não inicializando ${config.agentId} - status: ${currentStatus.status}`);
                    }
                }, 2000);
            }

            /**
             * 🔁 Reconectar apenas a instância (sem novo socket)
             */
            function reconnectInstance(config) {
                if (fatalSessionAgents.has(config.agentId)) {
                    console.warn(`🚫 Reconexão bloqueada para ${config.agentId}`);
                    return;
                }

                socketConnectInstance(config);
            }

            /**
             * 🔴 Desconectar instância específica
             */
            function socketDisconnectInstance(agentId) {
                if (socket && socket.connected) {
                    socket.emit('disconnectUser', agentId);
                }
            }

            // Handler para atualizações de status
            function handleStatusUpdate(data) {
                if (!data || !data.agent_id) {
                    console.warn('⚠️ Evento status inválido recebido');
                    return;
                }

                window.currentInstanceStatus = window.currentInstanceStatus || {};
                window.currentInstanceStatus[data.agent_id] = {
                    status: data.status,
                    timestamp: Date.now()
                };

                const isRelevantAgent = !!window.instanceAgentMap?.[data.agent_id];
                const isConnectedEvent = data.status === 'connected';

                // 🔴 SEMPRE processar eventos 'connected' (mesmo sem mapeamento)
                if (!isRelevantAgent && !data.broadcast && !data.direct && !isConnectedEvent) {
                    return;
                }

                // 🚨 LOG APENAS PARA EVENTOS IMPORTANTES
                if (data.status === 'connected' || data.requires_new_qr) {
                    console.log(`📊 Status ${data.agent_id}: ${data.status}${data.requires_new_qr ? ' (QR necessário)' : ''}`);
                }

                if (
                    ['qr', 'ready', 'connected'].includes(data.status) &&
                    window.qrTimeout
                ) {
                    clearTimeout(window.qrTimeout);
                    window.qrTimeout = null;
                }

                const instanceId =
                    window.instanceAgentMap?.[data.agent_id] ??
                    data.instance_id ??
                    null;

                const updateData = {
                    agent_id: data.agent_id,
                    instanceId,
                    status: data.status,
                    phoneNumber: data.phone_number || null,
                    qrCode: data.qrCode || data.qr_url || null,
                    requires_new_qr: data.requires_new_qr || false,
                    message: data.message || null
                };

                Livewire.dispatch('instanceStatusUpdated', {
                    data: updateData
                });
            }


            window.addEventListener('beforeunload', () => {
                if (socket) socket.disconnect();
                Object.values(window.statusCheckIntervals).forEach(clearInterval);
            });

            /**
             * 🔔 Livewire bindings
             */
            document.addEventListener('livewire:init', function() {

                Livewire.on('connectViaSocket', (data) => {
                    const config = data[0] || data;
                    socketConnectInstance(config);
                });

                Livewire.on('restartViaSocket', (data) => {
                    const config = data[0] || data;

                    if (socket && socket.connected) {
                        socket.emit('restart-instance', {
                            agentId: config.agentId,
                            userId: config.userId
                        });
                    } else {
                        console.error('❌ Socket não está conectado');
                    }
                });

                Livewire.on('disconnectViaSocket', (data) => {
                    const config = data[0] || data;
                    socketDisconnectInstance(config.agentId);
                });


                // Modal de criação
                Livewire.on('openCreateModal', function() {
                    const modal = new bootstrap.Modal(document.getElementById('createInstanceModal'));
                    modal.show();
                });

                // Modal de QR Code
                Livewire.on('closeCreateModal', function() {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('createInstanceModal'));
                    if (modal) {
                        modal.hide();
                    }
                });

                // 🆕 Polling: PHP dispara evento de navegador com agentId real;
                // a resposta chega no listener socket 'instance-status' (acima)
                Livewire.on('check-instance-status', function(payload) {
                    const config = (Array.isArray(payload) ? payload[0] : payload) || {};

                    if (!config.agentId || !socket || !socket.connected) {
                        return;
                    }

                    socket.emit('check-instance-status', {
                        agentId: config.agentId
                    });
                });

                // Verificação automática de status quando conectando
                Livewire.on('startStatusCheck', function(instanceId) {
                    // Evita criar múltiplos intervalos para a mesma instância
                    if (window.statusCheckIntervals && window.statusCheckIntervals[instanceId]) {
                        return;
                    }

                    // Garante que o objeto de intervalos existe
                    window.statusCheckIntervals = window.statusCheckIntervals || {};

                    // Limpa intervalo anterior se existir (segurança extra)
                    if (window.statusCheckIntervals[instanceId]) {
                        clearInterval(window.statusCheckIntervals[instanceId]);
                    }

                    window.statusCheckIntervals[instanceId] = setInterval(function() {
                        // Só executa se ainda temos o intervalo ativo
                        if (!window.statusCheckIntervals || !window.statusCheckIntervals[instanceId]) {
                            return;
                        }

                        Livewire.dispatch('checkInstanceStatus', {
                            instanceId: instanceId,
                            userId: @json($this->user->uuid)
                        });

                        // Para a verificação se o modal foi fechado ou se o componente foi removido
                        if (!document.getElementById('qrCodeModal') ||
                            !document.getElementById('qrCodeModal').classList.contains('show')) {
                            clearInterval(window.statusCheckIntervals[instanceId]);
                            delete window.statusCheckIntervals[instanceId];
                        }
                    }, 3000); // Verifica a cada 3 segundos
                });

                // Confirmação de exclusão de instância
                Livewire.on('showDeleteInstanceConfirm', function(data) {
                    const id = data[0].id;
                    const name = data[0].name;

                    Swal.fire({
                        title: 'Remover Instância?',
                        text: `Tem certeza que deseja remover a instância "${name}"? Esta ação não pode ser desfeita.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sim, remover',
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

                // Redirecionamento para página de planos
                Livewire.on('redirectToPlans', function() {
                    window.location.href = '{{ route('planos.index') }}';
                });

                // Evento para mostrar resultado da remoção
                Livewire.on('instanceDeleted', function(message) {
                    Swal.fire({
                        title: 'Sucesso!',
                        text: message,
                        icon: 'success',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    });
                });

                // Feedback de mensagens
                Livewire.on('message', function(message) {
                    Swal.fire({
                        title: 'Sucesso!',
                        text: message,
                        icon: 'success',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    });
                });

                Livewire.on('error', function(message) {
                    Swal.fire({
                        title: 'Erro!',
                        text: message,
                        icon: 'error',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#dc3545'
                    });
                });

                // Listener para quando modal for fechado
                Livewire.on('modalClosed', function() {
                    // Limpar timeout de QR code
                    if (window.qrTimeout) {
                        clearTimeout(window.qrTimeout);
                        window.qrTimeout = null;
                    }
                    // Limpar intervalos de verificação de status
                    if (window.statusCheckIntervals) {
                        Object.values(window.statusCheckIntervals).forEach(interval => {
                            clearInterval(interval);
                        });
                        window.statusCheckIntervals = {};
                    }
                });

                // Listener para quando componente Livewire for remontado
                Livewire.on('component:remounted', function() {
                    if (window.statusCheckIntervals) {
                        Object.values(window.statusCheckIntervals).forEach(interval => {
                            clearInterval(interval);
                        });
                        window.statusCheckIntervals = {};
                    }
                });

                // Listener para quando QR code for recebido
                Livewire.on('qrCodeReceived', function(data) {
                    // Verificar se o modal está visível
                    setTimeout(() => {
                        const modal = document.getElementById('qrCodeModal');
                        if (modal && !modal.classList.contains('show')) {
                            const bsModal = new bootstrap.Modal(modal);
                            bsModal.show();
                        }
                    }, 100);
                });

                // Listener para forçar abertura do modal
                Livewire.on('forceShowQrModal', function() {
                    setTimeout(() => {
                        const modal = document.getElementById('qrCodeModal');
                        if (modal && !modal.classList.contains('show')) {
                            const bsModal = new bootstrap.Modal(modal);
                            bsModal.show();
                        }
                    }, 100);
                });

                /**
                 * 🆕 REFRESH IMEDIATO DE QR CODE
                 * Usa o Socket.IO autenticado para gerar novo QR sem expor endpoint interno
                 * Benefício: Refresh em 2-3s ao invés de 5-10s
                 */
                window.refreshQRCode = async function(agentId) {
                    if (!agentId) {
                        console.error('❌ agentId não fornecido para refresh de QR');
                        return;
                    }

                    try {
                        // Mostrar loading
                        Swal.fire({
                            title: 'Atualizando QR Code...',
                            text: 'Gerando novo código em 2-3 segundos',
                            icon: 'info',
                            showConfirmButton: false,
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });

                        console.log(`🔄 Solicitando refresh de QR para ${agentId}`);

                        if (!socket || !socket.connected) {
                            throw new Error('Socket do WhatsApp ainda não está conectado.');
                        }

                        const data = await new Promise((resolve, reject) => {
                            const timeout = setTimeout(() => {
                                socket.off('qr-refresh-result', onResult);
                                reject(new Error('Tempo esgotado aguardando o refresh do QR Code.'));
                            }, 15000);

                            const onResult = (result) => {
                                if (result?.agent_id !== agentId) return;
                                clearTimeout(timeout);
                                socket.off('qr-refresh-result', onResult);
                                resolve(result);
                            };

                            socket.on('qr-refresh-result', onResult);
                            socket.emit('refresh-qr', { agentId });
                        });

                        if (data.success) {
                            Swal.fire({
                                title: 'Sucesso!',
                                text: 'Novo QR Code sendo gerado. Aguarde...',
                                icon: 'success',
                                timer: 2000,
                                showConfirmButton: false
                            });

                            console.log('✅ QR Code refresh solicitado com sucesso');
                        } else {
                            throw new Error(data.error || 'Erro ao atualizar QR Code');
                        }
                    } catch (error) {
                        console.error('❌ Erro ao refresh QR:', error);
                        Swal.fire({
                            title: 'Erro!',
                            text: error.message || 'Erro ao atualizar QR Code. Tente novamente.',
                            icon: 'error',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#dc3545'
                        });
                    }
                };

            });
        </script>
    @endsection
