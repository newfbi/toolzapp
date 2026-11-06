<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'CRM - Gerenciamento de Leads',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'CRM',
            ],
        ],
    ])

    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <!-- Cards de Estatísticas -->
        <div class="mb-4 col-12">
            <div class="row g-3">
                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Total de Leads</h6>
                                    <h3 class="mb-0">{{ number_format($stats['total_leads'], 0, ',', '.') }}</h3>
                                </div>
                                <div class="bg-primary-transparent avatar avatar-md">
                                    <i class="ri-group-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Leads Novos</h6>
                                    <h3 class="mb-0 text-info">
                                        {{ number_format($stats['new_leads_count'], 0, ',', '.') }}</h3>
                                </div>
                                <div class="bg-info-transparent avatar avatar-md">
                                    <i class="ri-user-add-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Contactados</h6>
                                    <h3 class="mb-0 text-warning">
                                        {{ number_format($stats['contacted_count'], 0, ',', '.') }}</h3>
                                </div>
                                <div class="bg-warning-transparent avatar avatar-md">
                                    <i class="ri-phone-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Qualificados</h6>
                                    <h3 class="mb-0 text-success">
                                        {{ number_format($stats['qualified_count'], 0, ',', '.') }}</h3>
                                    <span class="text-muted fs-12">{{ $stats['qualification_rate'] }}% do total</span>
                                </div>
                                <div class="bg-success-transparent avatar avatar-md">
                                    <i class="ri-checkbox-circle-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Convertidos</h6>
                                    <h3 class="mb-0 text-primary">
                                        {{ number_format($stats['converted_count'], 0, ',', '.') }}</h3>
                                    <span class="text-muted fs-12">{{ $stats['conversion_rate'] }}% do total</span>
                                </div>
                                <div class="bg-primary-transparent avatar avatar-md">
                                    <i class="ri-money-dollar-circle-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-3 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Score Médio</h6>
                                    <h3 class="mb-0 text-secondary">{{ $stats['average_score'] }}</h3>
                                    <span class="text-muted fs-12">de 100 pontos</span>
                                </div>
                                <div class="bg-secondary-transparent avatar avatar-md">
                                    <i class="ri-star-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros Avançados -->
        <div class="mb-4 col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="ri-filter-line me-2"></i>Filtros Avançados</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Buscar</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-search-line"></i></span>
                                <input type="text" class="form-control" placeholder="Nome, telefone ou mensagem..."
                                    wire:model.debounce.300ms="filterSearch">
                            </div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" wire:model="filterStatus">
                                <option value="">Todos</option>
                                <option value="new">Novos</option>
                                <option value="contacted">Contactados</option>
                                <option value="qualified">Qualificados</option>
                                <option value="won">Convertidos</option>
                                <option value="lost">Perdidos</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Qualificação</label>
                            <select class="form-select" wire:model="filterQualified">
                                <option value="">Todas</option>
                                <option value="qualified">Qualificados</option>
                                <option value="not_qualified">Não Qualificados</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Agente</label>
                            <select class="form-select" wire:model="filterAgent">
                                <option value="">Todos</option>
                                @foreach ($agents as $agent)
                                    <option value="{{ $agent->uuid }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Data</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="date" class="form-control" placeholder="De"
                                        wire:model="filterDateFrom">
                                </div>
                                <div class="col-6">
                                    <input type="date" class="form-control" placeholder="Até"
                                        wire:model="filterDateTo">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Score de Qualificação</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" class="form-control" placeholder="Mín"
                                        wire:model="filterScoreMin" min="0" max="100">
                                </div>
                                <div class="col-6">
                                    <input type="number" class="form-control" placeholder="Máx"
                                        wire:model="filterScoreMax" min="0" max="100">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn-outline-secondary btn" wire:click="clearFilters">
                                    <i class="me-1 ri-refresh-line"></i>Limpar Filtros
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela de Leads -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="me-auto mb-0 card-title">Leads do CRM</h4>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted fs-12">{{ $chats->total() }} leads encontrados</span>
                            <div class="btn-group" role="group">
                                <input type="radio" class="btn-check" name="viewMode" id="tableView"
                                    autocomplete="off" checked>
                                <label class="btn-outline-primary btn btn-sm" for="tableView">
                                    <i class="ri-table-line me-1"></i>Tabela
                                </label>
                                <input type="radio" class="btn-check" name="viewMode" id="kanbanView"
                                    autocomplete="off">
                                <label class="btn-outline-primary btn btn-sm" for="kanbanView">
                                    <i class="me-1 ri-kanban-view"></i>Kanban
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="table-view" class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" width="100%">
                            <thead>
                                <tr>
                                    <th>Lead</th>
                                    <th>Agente</th>
                                    <th>Status</th>
                                    <th>Qualificação</th>
                                    <th>Score</th>
                                    <th>Data</th>
                                    <th>Mensagens</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($chats as $chat)
                                    @php
                                        $stream = app(
                                            \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                        )->getLastStream($chat);
                                        $qualification = null;
                                        $leadStatus = 'new';

                                        if ($stream && $stream->lead_qualification) {
                                            $qualification = $stream->lead_qualification;
                                            if (is_string($qualification)) {
                                                $qualification = json_decode($qualification, true);
                                            }
                                            if (!is_array($qualification)) {
                                                $qualification = null;
                                            }
                                        }

                                        // Determina status do lead
                                        if (
                                            $chat->conversion === true ||
                                            $chat->conversion === 'true' ||
                                            $chat->conversion === 1
                                        ) {
                                            $leadStatus = 'won';
                                        } elseif ($qualification && ($qualification['qualified'] ?? false)) {
                                            $leadStatus = 'qualified';
                                        } elseif (($chat->messages_count ?? 0) > 0) {
                                            $leadStatus = 'contacted';
                                        }

                                        $conversationLog = $stream ? $stream->conversation_log ?? [] : [];
                                        if (is_string($conversationLog)) {
                                            $conversationLog = json_decode($conversationLog, true);
                                        }
                                        $totalMessages = count($conversationLog['history'] ?? []);
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="flex-shrink-0">
                                                    <img src="{{ $chat->agent && $chat->agent->avatar ? '/assets/img/' . $chat->agent->avatar : '/assets/images/avatar/default.png' }}"
                                                        class="rounded-circle avatar-sm" alt="avatar">
                                                </div>
                                                <div>
                                                    <h6 class="mb-0">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                    <small class="text-muted">{{ $chat->phone }}</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="bg-primary-transparent badge">
                                                {{ $chat->agent ? $chat->agent->name : 'N/A' }}
                                            </span>
                                        </td>
                                        <td>
                                            @php
                                                $statusConfig = [
                                                    'new' => ['label' => 'Novo', 'class' => 'secondary'],
                                                    'contacted' => ['label' => 'Contactado', 'class' => 'warning'],
                                                    'qualified' => ['label' => 'Qualificado', 'class' => 'success'],
                                                    'won' => ['label' => 'Convertido', 'class' => 'primary'],
                                                    'lost' => ['label' => 'Perdido', 'class' => 'danger'],
                                                ];
                                                $statusInfo = $statusConfig[$leadStatus] ?? $statusConfig['new'];
                                            @endphp
                                            <span class="badge bg-{{ $statusInfo['class'] }}">
                                                {{ $statusInfo['label'] }}
                                            </span>
                                        </td>
                                        <td>
                                            @if ($qualification && isset($qualification['qualified']))
                                                @if ($qualification['qualified'])
                                                    <span class="bg-success badge">
                                                        <i class="ri-check-line"></i> Sim
                                                    </span>
                                                @else
                                                    <span class="bg-danger badge">
                                                        <i class="ri-close-line"></i> Não
                                                    </span>
                                                @endif
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($qualification && isset($qualification['score']))
                                                @php
                                                    $score = $qualification['score'];
                                                    $scoreColor =
                                                        $score >= 70
                                                            ? 'success'
                                                            : ($score >= 50
                                                                ? 'warning'
                                                                : 'danger');
                                                @endphp
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-{{ $scoreColor }}-transparent">
                                                        {{ $score }}/100
                                                    </span>
                                                </div>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                {{ $chat->created_at->format('d/m/Y') }}
                                            </small>
                                            <br>
                                            <small class="text-muted">
                                                {{ $chat->created_at->format('H:i') }}
                                            </small>
                                        </td>
                                        <td>
                                            <span class="bg-light badge">{{ $totalMessages }}</span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <!-- Dropdown de ações de status -->
                                                <div class="dropdown">
                                                    <button class="btn-outline-primary btn btn-sm dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown">
                                                        Status
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item"
                                                                wire:click="updateLeadStatus('{{ $chat->uuid }}', 'new')"
                                                                href="#">Novo</a></li>
                                                        <li><a class="dropdown-item"
                                                                wire:click="updateLeadStatus('{{ $chat->uuid }}', 'contacted')"
                                                                href="#">Contactado</a></li>
                                                        <li><a class="dropdown-item"
                                                                wire:click="updateLeadStatus('{{ $chat->uuid }}', 'qualified')"
                                                                href="#">Qualificado</a></li>
                                                        <li><a class="dropdown-item"
                                                                wire:click="updateLeadStatus('{{ $chat->uuid }}', 'won')"
                                                                href="#">Convertido</a></li>
                                                        <li><a class="dropdown-item"
                                                                wire:click="updateLeadStatus('{{ $chat->uuid }}', 'lost')"
                                                                href="#">Perdido</a></li>
                                                    </ul>
                                                </div>

                                                <button class="btn icon-btn-sm btn-light-info"
                                                    wire:click="showLeadDetails('{{ $chat->uuid }}')"
                                                    title="Ver Detalhes">
                                                    <i class="ri-eye-line"></i>
                                                </button>

                                                <button class="btn icon-btn-sm btn-light-primary"
                                                    wire:click="forceAnalysis('{{ $chat->uuid }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="forceAnalysis('{{ $chat->uuid }}')"
                                                    title="Forçar Nova Análise">
                                                    <i class="ri-refresh-line"></i>
                                                </button>

                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="py-5 text-center">
                                            <div class="text-muted">
                                                <i class="ri-inbox-line fs-48"></i>
                                                <p class="mt-2 mb-0">Nenhum lead encontrado</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($chats->hasPages())
                        <div class="mt-3">
                            {{ $chats->links() }}
                        </div>
                    @endif
                </div>
            </div>

            <!-- Visualização Kanban (inicialmente oculta) -->
            <div id="kanban-view" class="d-none">
                <div class="d-flex gap-4 pb-4 overflow-x-scroll">
                    <!-- Coluna: Novos -->
                    <div class="flex-shrink-0 kanban-list" style="width: 300px;">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <div>
                                <span class="bg-secondary-subtle text-secondary badge">Novos</span>
                                @php
                                    $newLeadsCount = collect($chats->items())
                                        ->filter(function ($chat) {
                                            $stream = app(
                                                \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                            )->getLastStream($chat);
                                            $qualification = null;
                                            if ($stream && $stream->lead_qualification) {
                                                $qualification = $stream->lead_qualification;
                                                if (is_string($qualification)) {
                                                    $qualification = json_decode($qualification, true);
                                                }
                                                if (!is_array($qualification)) {
                                                    $qualification = null;
                                                }
                                            }
                                            return !$qualification ||
                                                !isset($qualification['qualified']) ||
                                                (!$qualification['qualified'] && !$chat->conversion);
                                        })
                                        ->count();
                                @endphp
                                <span class="bg-secondary ms-2 badge">{{ $newLeadsCount }}</span>
                            </div>
                        </div>
                        <div id="kanban-new" class="kanban-container" style="min-height: 400px;">
                            @foreach ($chats as $chat)
                                @php
                                    $stream = app(
                                        \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                    )->getLastStream($chat);
                                    $qualification = null;
                                    $currentStatus = 'new';

                                    if ($stream && $stream->lead_qualification) {
                                        $qualification = $stream->lead_qualification;
                                        if (is_string($qualification)) {
                                            $qualification = json_decode($qualification, true);
                                        }
                                        if (!is_array($qualification)) {
                                            $qualification = null;
                                        }
                                    }

                                    if (
                                        $chat->conversion === true ||
                                        $chat->conversion === 'true' ||
                                        $chat->conversion === 1
                                    ) {
                                        $currentStatus = 'won';
                                    } elseif ($qualification && ($qualification['qualified'] ?? false)) {
                                        $currentStatus = 'qualified';
                                    } elseif (($chat->messages_count ?? 0) > 0) {
                                        $currentStatus = 'contacted';
                                    }
                                @endphp
                                @if ($currentStatus === 'new')
                                    <div class="mb-3 border card" data-chat-id="{{ $chat->uuid }}"
                                        style="cursor: grab;">
                                        <div class="p-3 card-body">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <h6 class="mb-1 card-title">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                <small class="text-muted">{{ $chat->phone }}</small>
                                            </div>
                                            <p class="mb-2 text-muted card-text small">
                                                {{ Str::limit($chat->last_message ?? 'Sem mensagem', 50) }}</p>
                                            <div class="d-flex align-items-center justify-content-between">
                                                <small
                                                    class="text-muted">{{ $chat->created_at->format('d/m') }}</small>
                                                <span
                                                    class="bg-light text-dark badge">{{ $chat->messages_count ?? 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <!-- Coluna: Contactados -->
                    <div class="flex-shrink-0 kanban-list" style="width: 300px;">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <div>
                                <span class="bg-warning-subtle text-warning badge">Contactados</span>
                                @php
                                    $contactedCount = collect($chats->items())
                                        ->filter(function ($chat) {
                                            $stream = app(
                                                \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                            )->getLastStream($chat);
                                            $qualification = null;
                                            if ($stream && $stream->lead_qualification) {
                                                $qualification = $stream->lead_qualification;
                                                if (is_string($qualification)) {
                                                    $qualification = json_decode($qualification, true);
                                                }
                                                if (!is_array($qualification)) {
                                                    $qualification = null;
                                                }
                                            }
                                            return (!$qualification ||
                                                !isset($qualification['qualified']) ||
                                                !$qualification['qualified']) &&
                                                !$chat->conversion &&
                                                ($chat->messages_count ?? 0) > 0;
                                        })
                                        ->count();
                                @endphp
                                <span class="bg-secondary ms-2 badge">{{ $contactedCount }}</span>
                            </div>
                        </div>
                        <div id="kanban-contacted" class="kanban-container" style="min-height: 400px;">
                            @foreach ($chats as $chat)
                                @php
                                    $stream = app(
                                        \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                    )->getLastStream($chat);
                                    $qualification = null;
                                    $currentStatus = 'new';

                                    if ($stream && $stream->lead_qualification) {
                                        $qualification = $stream->lead_qualification;
                                        if (is_string($qualification)) {
                                            $qualification = json_decode($qualification, true);
                                        }
                                        if (!is_array($qualification)) {
                                            $qualification = null;
                                        }
                                    }

                                    if (
                                        $chat->conversion === true ||
                                        $chat->conversion === 'true' ||
                                        $chat->conversion === 1
                                    ) {
                                        $currentStatus = 'won';
                                    } elseif ($qualification && ($qualification['qualified'] ?? false)) {
                                        $currentStatus = 'qualified';
                                    } elseif (($chat->messages_count ?? 0) > 0) {
                                        $currentStatus = 'contacted';
                                    }
                                @endphp
                                @if ($currentStatus === 'contacted')
                                    <div class="mb-3 border card" data-chat-id="{{ $chat->uuid }}"
                                        style="cursor: grab;">
                                        <div class="p-3 card-body">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <h6 class="mb-1 card-title">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                <small class="text-muted">{{ $chat->phone }}</small>
                                            </div>
                                            <p class="mb-2 text-muted card-text small">
                                                {{ Str::limit($chat->last_message ?? 'Sem mensagem', 50) }}</p>
                                            <div class="d-flex align-items-center justify-content-between">
                                                <small
                                                    class="text-muted">{{ $chat->created_at->format('d/m') }}</small>
                                                <span
                                                    class="bg-light text-dark badge">{{ $chat->messages_count ?? 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <!-- Coluna: Qualificados -->
                    <div class="flex-shrink-0 kanban-list" style="width: 300px;">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <div>
                                <span class="bg-success-subtle text-success badge">Qualificados</span>
                                @php
                                    $qualifiedCount = collect($chats->items())
                                        ->filter(function ($chat) {
                                            $stream = app(
                                                \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                            )->getLastStream($chat);
                                            $qualification = null;
                                            if ($stream && $stream->lead_qualification) {
                                                $qualification = $stream->lead_qualification;
                                                if (is_string($qualification)) {
                                                    $qualification = json_decode($qualification, true);
                                                }
                                                if (!is_array($qualification)) {
                                                    $qualification = null;
                                                }
                                            }
                                            return $qualification &&
                                                isset($qualification['qualified']) &&
                                                $qualification['qualified'] &&
                                                !$chat->conversion;
                                        })
                                        ->count();
                                @endphp
                                <span class="bg-secondary ms-2 badge">{{ $qualifiedCount }}</span>
                            </div>
                        </div>
                        <div id="kanban-qualified" class="kanban-container" style="min-height: 400px;">
                            @foreach ($chats as $chat)
                                @php
                                    $stream = app(
                                        \App\Http\Controllers\Knowledge\Repositories\StreamRepository::class,
                                    )->getLastStream($chat);
                                    $qualification = null;
                                    $currentStatus = 'new';

                                    if ($stream && $stream->lead_qualification) {
                                        $qualification = $stream->lead_qualification;
                                        if (is_string($qualification)) {
                                            $qualification = json_decode($qualification, true);
                                        }
                                        if (!is_array($qualification)) {
                                            $qualification = null;
                                        }
                                    }

                                    if (
                                        $chat->conversion === true ||
                                        $chat->conversion === 'true' ||
                                        $chat->conversion === 1
                                    ) {
                                        $currentStatus = 'won';
                                    } elseif ($qualification && ($qualification['qualified'] ?? false)) {
                                        $currentStatus = 'qualified';
                                    } elseif (($chat->messages_count ?? 0) > 0) {
                                        $currentStatus = 'contacted';
                                    }
                                @endphp
                                @if ($currentStatus === 'qualified')
                                    <div class="mb-3 border card" data-chat-id="{{ $chat->uuid }}"
                                        style="cursor: grab;">
                                        <div class="p-3 card-body">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <h6 class="mb-1 card-title">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                <small class="text-muted">{{ $chat->phone }}</small>
                                            </div>
                                            <p class="mb-2 text-muted card-text small">
                                                {{ Str::limit($chat->last_message ?? 'Sem mensagem', 50) }}</p>
                                            @if ($qualification && isset($qualification['score']))
                                                <div class="mb-2">
                                                    <small class="text-success">Score:
                                                        {{ $qualification['score'] }}/100</small>
                                                </div>
                                            @endif
                                            <div class="d-flex align-items-center justify-content-between">
                                                <small
                                                    class="text-muted">{{ $chat->created_at->format('d/m') }}</small>
                                                <span
                                                    class="bg-light text-dark badge">{{ $chat->messages_count ?? 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <!-- Coluna: Convertidos -->
                    <div class="flex-shrink-0 kanban-list" style="width: 300px;">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <div>
                                <span class="bg-primary-subtle text-primary badge">Convertidos</span>
                                @php
                                    $convertedCount = collect($chats->items())
                                        ->filter(function ($chat) {
                                            return $chat->conversion === true ||
                                                $chat->conversion === 'true' ||
                                                $chat->conversion === 1;
                                        })
                                        ->count();
                                @endphp
                                <span class="bg-secondary ms-2 badge">{{ $convertedCount }}</span>
                            </div>
                        </div>
                        <div id="kanban-won" class="kanban-container" style="min-height: 400px;">
                            @foreach ($chats as $chat)
                                @if ($chat->conversion === true || $chat->conversion === 'true' || $chat->conversion === 1)
                                    <div class="mb-3 border card" data-chat-id="{{ $chat->uuid }}"
                                        style="cursor: grab;">
                                        <div class="p-3 card-body">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <h6 class="mb-1 card-title">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                <small class="text-muted">{{ $chat->phone }}</small>
                                            </div>
                                            <p class="mb-2 text-muted card-text small">
                                                {{ Str::limit($chat->last_message ?? 'Sem mensagem', 50) }}</p>
                                            <div class="mb-2">
                                                <span class="bg-success badge">✓ Convertido</span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between">
                                                <small
                                                    class="text-muted">{{ $chat->created_at->format('d/m') }}</small>
                                                <span
                                                    class="bg-light text-dark badge">{{ $chat->messages_count ?? 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <!-- Coluna: Perdidos -->
                    <div class="flex-shrink-0 kanban-list" style="width: 300px;">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom">
                            <div>
                                <span class="bg-danger-subtle text-danger badge">Perdidos</span>
                                @php
                                    $lostCount = collect($chats->items())
                                        ->filter(function ($chat) {
                                            return $chat->conversion === false ||
                                                $chat->conversion === 'false' ||
                                                $chat->conversion === 0;
                                        })
                                        ->count();
                                @endphp
                                <span class="bg-secondary ms-2 badge">{{ $lostCount }}</span>
                            </div>
                        </div>
                        <div id="kanban-lost" class="kanban-container" style="min-height: 400px;">
                            @foreach ($chats as $chat)
                                @if ($chat->conversion === false || $chat->conversion === 'false' || $chat->conversion === 0)
                                    <div class="mb-3 border card" data-chat-id="{{ $chat->uuid }}"
                                        style="cursor: grab;">
                                        <div class="p-3 card-body">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <h6 class="mb-1 card-title">{{ $chat->name ?? 'Sem nome' }}</h6>
                                                <small class="text-muted">{{ $chat->phone }}</small>
                                            </div>
                                            <p class="mb-2 text-muted card-text small">
                                                {{ Str::limit($chat->last_message ?? 'Sem mensagem', 50) }}</p>
                                            <div class="mb-2">
                                                <span class="bg-danger badge">✗ Perdido</span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between">
                                                <small
                                                    class="text-muted">{{ $chat->created_at->format('d/m') }}</small>
                                                <span
                                                    class="bg-light text-dark badge">{{ $chat->messages_count ?? 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Detalhes do Lead -->
    @if ($showDetailsModal && $selectedChat)
        <div class="d-block modal fade show" id="leadDetailsModal" tabindex="-1"
            style="background-color: rgba(0,0,0,0.5); z-index: 1055;" wire:ignore.self>
            <div class="modal-backdrop fade show"
                style="z-index: 1054; position: fixed; top: 0; left: 0; width: 100%; height: 100%;"
                wire:click="closeModals"></div>
            @php
                $streamRepository = app(\App\Http\Controllers\Knowledge\Repositories\StreamRepository::class);
                $stream = $streamRepository->getLastStream($selectedChat);
                $qualificationData = null;

                if ($stream && $stream->lead_qualification) {
                    $qualification = $stream->lead_qualification;
                    if (is_string($qualification)) {
                        $qualification = json_decode($qualification, true);
                    }
                    if (!is_array($qualification)) {
                        $qualification = null;
                    }

                    $qualificationData = [
                        'qualified' => $qualification['qualified'] ?? false,
                        'score' => $qualification['score'] ?? 0,
                        'reasons' => $qualification['reasons'] ?? [],
                        'analysis' => $qualification['analysis'] ?? '',
                        'confidence' => $qualification['confidence'] ?? null,
                        'indicators' => $qualification['indicators'] ?? [],
                    ];

                    $conversationLog = $stream->conversation_log ?? [];
                    if (is_string($conversationLog)) {
                        $conversationLog = json_decode($conversationLog, true);
                    }
                    $history = $conversationLog['history'] ?? [];
                    $qualificationData['total_messages'] = count($history);
                }
            @endphp
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"
                style="z-index: 1056; position: relative;">
                <div class="modal-content" style="z-index: 1056; position: relative;"
                    onclick="event.stopPropagation();">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="me-2 ri-user-search-line"></i>
                            Detalhes do Lead - {{ $selectedChat->name }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="closeModals"></button>
                    </div>
                    <div class="modal-body">
                        @if ($qualificationData)
                            <!-- Informações do Lead -->
                            <div class="mb-3 card">
                                <div class="bg-light card-header">
                                    <h6 class="mb-0"><i class="me-2 ri-user-line"></i>Informações do Lead</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Nome:</strong>
                                                {{ $selectedChat->name ?? 'N/A' }}</p>
                                            <p class="mb-2"><strong>Telefone:</strong>
                                                {{ preg_replace('/@.*$/', '', $selectedChat->phone) }}</p>
                                            <p class="mb-2"><strong>Agente:</strong>
                                                {{ $selectedChat->agent ? $selectedChat->agent->name : 'N/A' }}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Total de Mensagens:</strong>
                                                {{ $qualificationData['total_messages'] ?? 0 }}</p>
                                            <p class="mb-2"><strong>Status:</strong>
                                                @php
                                                    $currentStatus = 'new';
                                                    if (
                                                        $selectedChat->conversion === true ||
                                                        $selectedChat->conversion === 'true' ||
                                                        $selectedChat->conversion === 1
                                                    ) {
                                                        $currentStatus = 'won';
                                                    } elseif ($qualificationData['qualified'] ?? false) {
                                                        $currentStatus = 'qualified';
                                                    } elseif (($qualificationData['total_messages'] ?? 0) > 0) {
                                                        $currentStatus = 'contacted';
                                                    }
                                                    $statusLabels = [
                                                        'new' => 'Novo',
                                                        'contacted' => 'Contactado',
                                                        'qualified' => 'Qualificado',
                                                        'won' => 'Convertido',
                                                        'lost' => 'Perdido',
                                                    ];
                                                @endphp
                                                <span
                                                    class="bg-primary badge">{{ $statusLabels[$currentStatus] ?? 'Novo' }}</span>
                                            </p>
                                            <p class="mb-2"><strong>Data:</strong>
                                                {{ $selectedChat->created_at->format('d/m/Y H:i') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Score e Qualificação -->
                            <div class="mb-3 card">
                                <div class="bg-light card-header">
                                    <h6 class="mb-0"><i class="me-2 ri-star-line"></i>Qualificação</h6>
                                </div>
                                <div class="card-body">
                                    <div class="align-items-center row">
                                        <div class="text-center col-md-4">
                                            @php
                                                $score = $qualificationData['score'] ?? 0;
                                                $scoreColor =
                                                    $score >= 70 ? 'success' : ($score >= 50 ? 'warning' : 'danger');
                                            @endphp
                                            <h2 class="mb-0 text-{{ $scoreColor }}">{{ $score }}</h2>
                                            <p class="mb-0 text-muted">de 100 pontos</p>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="mb-2">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Score</span>
                                                    <span>{{ $score }}%</span>
                                                </div>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-{{ $scoreColor }}"
                                                        role="progressbar" style="width: {{ $score }}%">
                                                        {{ $score }}%
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <span
                                                    class="badge bg-{{ $qualificationData['qualified'] ? 'success' : 'danger' }} fs-14">
                                                    @if ($qualificationData['qualified'])
                                                        <i class="ri-check-line"></i> Lead Qualificado
                                                    @else
                                                        <i class="ri-close-line"></i> Lead Não Qualificado
                                                    @endif
                                                </span>
                                                @if (isset($qualificationData['confidence']))
                                                    <span class="bg-info-transparent ms-2 badge">
                                                        Confiança:
                                                        {{ number_format($qualificationData['confidence'] * 100, 1) }}%
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Análise Detalhada -->
                            @if (!empty($qualificationData['analysis']))
                                <div class="mb-3 card">
                                    <div class="bg-light card-header">
                                        <h6 class="mb-0"><i class="me-2 ri-file-text-line"></i>Análise Detalhada
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0 text-muted">{{ $qualificationData['analysis'] }}</p>
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="py-5 text-center">
                                <i class="text-muted ri-information-line fs-48"></i>
                                <p class="mt-3 mb-0 text-muted">Este lead ainda não possui análise de qualificação.</p>
                                <button class="mt-3 btn btn-primary"
                                    wire:click="forceAnalysis('{{ $selectedChat->uuid }}')">
                                    <i class="me-2 ri-refresh-line"></i>Realizar Análise Agora
                                </button>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeModals">Fechar</button>
                        @if ($qualificationData)
                            <button type="button" class="btn btn-primary"
                                wire:click="forceAnalysis('{{ $selectedChat->uuid }}')">
                                <i class="me-2 ri-refresh-line"></i>Forçar Nova Análise
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @push('css')
        <link rel="stylesheet" href="{{ asset('assets/libs/dragula/dragula.min.css') }}">
        <style>
            .kanban-container {
                padding: 1rem 0;
                min-height: 400px;
                border-radius: 0.375rem;
            }

            .kanban-container .card {
                transition: all 0.2s ease;
            }

            .kanban-container .card:hover {
                box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
                transform: translateY(-1px);
            }

            .kanban-container .is-dragging {
                opacity: 0.5;
                transform: rotate(2deg);
                box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            }

            .kanban-list {
                background: #f8f9fa;
                border-radius: 0.375rem;
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .gu-mirror {
                position: fixed !important;
                margin: 0 !important;
                z-index: 9999 !important;
                opacity: 0.8;
                transform-origin: left top;
            }

            .gu-hide {
                display: none !important;
            }

            .gu-unselectable {
                -webkit-user-select: none !important;
                -moz-user-select: none !important;
                -ms-user-select: none !important;
                user-select: none !important;
            }

            .gu-transit {
                opacity: 0.2 !important;
            }
        </style>
    @endpush

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="{{ asset('assets/libs/dragula/dragula.min.js') }}"></script>
        <script>
            document.addEventListener('livewire:init', function() {
                // Adicionar classe modal-open ao body quando modal estiver aberto
                Livewire.hook('morph.updated', () => {
                    setTimeout(() => {
                        const modalElement = document.getElementById('leadDetailsModal');
                        const modalOpen = modalElement && modalElement.classList.contains('show');

                        if (modalOpen && !document.body.classList.contains('modal-open')) {
                            document.body.classList.add('modal-open');
                            document.body.style.overflow = 'hidden';
                            document.body.style.paddingRight = '0px';
                        } else if (!modalOpen && document.body.classList.contains('modal-open')) {
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                            document.body.style.paddingRight = '';
                        }
                    }, 50);
                });

                // Limpar estado quando modal for fechado pelo backdrop
                document.addEventListener('click', function(e) {
                    const backdrop = e.target;
                    if (backdrop.classList && backdrop.classList.contains('modal-backdrop')) {
                        const modal = document.getElementById('leadDetailsModal');
                        if (modal && modal.contains(backdrop)) {
                            @this.set('showDetailsModal', false);
                            @this.set('selectedChat', null);
                        }
                    }
                });

                // Listener para quando análise for concluída
                Livewire.on('analysis-completed', (event) => {
                    if (@this.showDetailsModal && @this.selectedChat && event.detail && event.detail.chatId) {
                        setTimeout(() => {
                            @this.call('showLeadDetails', event.detail.chatId);
                        }, 500);
                    }
                });

                // Listener para atualização de status via drag and drop
                Livewire.on('lead-status-updated', (event) => {
                    // Feedback visual
                    console.log('Status do lead atualizado:', event.detail);
                });

                // Alternar entre visualizações
                document.querySelectorAll('input[name="viewMode"]').forEach(radio => {
                    radio.addEventListener('change', function() {
                        const tableView = document.getElementById('table-view');
                        const kanbanView = document.getElementById('kanban-view');

                        if (this.id === 'tableView') {
                            tableView.classList.remove('d-none');
                            kanbanView.classList.add('d-none');
                        } else if (this.id === 'kanbanView') {
                            tableView.classList.add('d-none');
                            kanbanView.classList.remove('d-none');
                            // Reinicializar drag and drop quando mudar para kanban
                            setTimeout(() => initializeKanbanDragDrop(), 100);
                        }
                    });
                });

                // Inicializar drag and drop para kanban (se existir)
                initializeKanbanDragDrop();
            });

            function initializeKanbanDragDrop() {
                // Verificar se existem elementos kanban na página
                const kanbanContainers = ['#kanban-new', '#kanban-contacted', '#kanban-qualified', '#kanban-won',
                    '#kanban-lost'
                ];

                const validContainers = kanbanContainers.filter(selector => {
                    const element = document.querySelector(selector);
                    return element !== null;
                });

                if (validContainers.length > 0) {
                    const drake = dragula(validContainers.map(selector => document.querySelector(selector)), {
                        moves: function(el, source, handle, sibling) {
                            // Permitir drag apenas se não for um elemento de controle
                            return !el.classList.contains('no-drag') && !handle.classList.contains('no-drag');
                        },
                        accepts: function(el, target, source, sibling) {
                            // Aceitar qualquer container válido
                            return validContainers.includes('#' + target.id);
                        }
                    });

                    // Event listener para quando um elemento é solto
                    drake.on('drop', function(el, target, source, sibling) {
                        if (!target || !source || target.id === source.id) return;

                        // Extrair o status do ID do container destino
                        const statusMap = {
                            'kanban-new': 'new',
                            'kanban-contacted': 'contacted',
                            'kanban-qualified': 'qualified',
                            'kanban-won': 'won',
                            'kanban-lost': 'lost'
                        };

                        const newStatus = statusMap[target.id];
                        if (!newStatus) return;

                        // Extrair o chatId do elemento
                        const chatId = el.getAttribute('data-chat-id');
                        if (!chatId) return;

                        // Chamar o método Livewire para atualizar o status
                        @this.call('updateLeadStatus', chatId, newStatus);

                        // Feedback visual
                        showToast('Lead movido para: ' + getStatusLabel(newStatus), 'success');
                    });

                    drake.on('drag', function(el, source) {
                        el.classList.add('is-dragging');
                    });

                    drake.on('dragend', function(el) {
                        el.classList.remove('is-dragging');
                    });
                }
            }

            function getStatusLabel(status) {
                const labels = {
                    'new': 'Novo',
                    'contacted': 'Contactado',
                    'qualified': 'Qualificado',
                    'won': 'Convertido',
                    'lost': 'Perdido'
                };
                return labels[status] || status;
            }

            function showToast(message, type = 'info') {
                // Usar SweetAlert2 para notificações visuais
                if (typeof Swal !== 'undefined') {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer);
                            toast.addEventListener('mouseleave', Swal.resumeTimer);
                        }
                    });

                    Toast.fire({
                        icon: type,
                        title: message
                    });
                } else {
                    // Fallback para console.log se Swal não estiver disponível
                    console.log(message);
                }
            }
        </script>
    @endpush
</div>
