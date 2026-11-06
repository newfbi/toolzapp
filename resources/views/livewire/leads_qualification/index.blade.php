<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Qualificação de Leads',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Qualificação de Leads',
            ],
        ],
    ])

    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>

        <!-- Estatísticas Gerais -->
        <div class="mb-4 col-12">
            <div class="row g-3">
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Total de Chats</h6>
                                    <h3 class="mb-0">{{ number_format($stats['total_chats'], 0, ',', '.') }}</h3>
                                </div>
                                <div class="bg-primary-transparent avatar avatar-md">
                                    <i class="ri-chat-3-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Atendimentos</h6>
                                    <h3 class="mb-0 text-warning">
                                        {{ number_format($stats['conversion_count'], 0, ',', '.') }}</h3>
                                    <span class="text-muted fs-12">{{ $stats['conversion_percentage'] }}% do
                                        total</span>
                                </div>
                                <div class="bg-warning-transparent avatar avatar-md">
                                    <i class="ri-money-dollar-circle-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Score Médio</h6>
                                    <h3 class="mb-0 text-info">{{ $stats['average_score'] }}</h3>
                                    <span class="text-muted fs-12">de 100 pontos</span>
                                </div>
                                <div class="bg-info-transparent avatar avatar-md">
                                    <i class="ri-star-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="mb-1 text-muted">Leads Qualificados</h6>
                                    <h3 class="mb-0 text-success">
                                        {{ number_format($stats['qualified_count'], 0, ',', '.') }}</h3>
                                    <span class="text-muted fs-12">{{ $stats['qualified_percentage'] }}% do total</span>
                                </div>
                                <div class="bg-success-transparent avatar avatar-md">
                                    <i class="ri-checkbox-circle-line fs-20"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros e Tabela -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex align-items-center">
                        <h4 class="me-auto mb-0 card-title">Chats e Qualificações</h4>
                        <div class="d-flex align-items-center gap-2">
                            <!-- Filtro de Busca -->
                            <div class="input-group input-group-sm" style="width: 220px;">
                                <span class="input-group-text bg-light border-end-0"><i
                                        class="ri-search-line fs-14"></i></span>
                                <input type="text" class="border-start-0 form-control" placeholder="Buscar..."
                                    wire:model.debounce.300ms="filterSearch">
                            </div>

                            <!-- Filtro por Status -->
                            <select class="form-select-sm form-select" wire:model="filterQualified"
                                style="width: 160px;">
                                <option value="">Todos</option>
                                <option value="qualified">Qualificados</option>
                                <option value="not_qualified">Não Qualificados</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" width="100%">
                            <thead>
                                <tr>
                                    <th>Chat</th>
                                    <th>Agente</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Qualificado</th>
                                    <th>Última Análise</th>
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

                                        if ($stream && $stream->lead_qualification) {
                                            $qualification = $stream->lead_qualification;
                                            // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
                                            if (is_string($qualification)) {
                                                $qualification = json_decode($qualification, true);
                                            }
                                            if (!is_array($qualification)) {
                                                $qualification = null;
                                            }
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
                                                $isConverted =
                                                    $chat->conversion === true ||
                                                    $chat->conversion === 'true' ||
                                                    $chat->conversion === 1;
                                                $isPaused = $chat->is_pause === 'true' || $chat->is_pause === true;
                                            @endphp
                                            @if ($isConverted)
                                                <span class="bg-success badge">Convertido</span>
                                            @elseif ($isPaused)
                                                <span class="bg-warning badge">Pausado</span>
                                            @else
                                                <span class="bg-info badge">Em atendimento</span>
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
                                                    <div class="progress" style="width: 60px; height: 6px;">
                                                        <div class="progress-bar bg-{{ $scoreColor }}"
                                                            role="progressbar" style="width: {{ $score }}%">
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
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
                                                <span class="text-muted">Não analisado</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($stream && $stream->last_qualification_analysis)
                                                @php
                                                    $lastAnalysis = \Carbon\Carbon::parse(
                                                        $stream->last_qualification_analysis,
                                                    );
                                                @endphp
                                                <small class="text-muted">
                                                    {{ $lastAnalysis->format('d/m/Y H:i') }}
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    {{ $lastAnalysis->diffForHumans() }}
                                                </small>
                                            @else
                                                <span class="text-muted">--</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="bg-light badge">{{ $totalMessages }}</span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <button class="btn icon-btn-sm btn-light-info"
                                                    wire:click="showQualificationDetails('{{ $chat->uuid }}')"
                                                    wire:loading.attr="disabled" wire:target="showQualificationDetails"
                                                    title="Ver Detalhes">
                                                    <i class="ri-eye-line"></i>
                                                </button>
                                                <button class="btn icon-btn-sm btn-light-primary"
                                                    wire:click="forceAnalysis('{{ $chat->uuid }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="forceAnalysis('{{ $chat->uuid }}')"
                                                    @if ($isProcessing && $processingChatId === $chat->uuid) disabled @endif
                                                    title="@if ($isProcessing && $processingChatId === $chat->uuid) Processando... @else Forçar Nova Análise @endif">
                                                    <span wire:loading.remove
                                                        wire:target="forceAnalysis('{{ $chat->uuid }}')">
                                                        <i class="ri-refresh-line"></i>
                                                    </span>
                                                    <span wire:loading
                                                        wire:target="forceAnalysis('{{ $chat->uuid }}')">
                                                        <span class="spinner-border spinner-border-sm" role="status"
                                                            aria-hidden="true"></span>
                                                    </span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-5 text-center">
                                            <div class="text-muted">
                                                <i class="ri-inbox-line fs-48"></i>
                                                <p class="mt-2 mb-0">Nenhum chat encontrado</p>
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
        </div>
    </div>

    <!-- Modal de Detalhes da Qualificação -->
    @if ($showDetailsModal && $selectedChat)
        <div class="d-block modal fade show" id="qualificationDetailsModal" tabindex="-1"
            style="background-color: rgba(0,0,0,0.5); z-index: 1055;" wire:ignore.self>
            <div class="modal-backdrop fade show"
                style="z-index: 1054; position: fixed; top: 0; left: 0; width: 100%; height: 100%;"
                wire:click="closeDetailsModal"></div>
            @php
                $streamRepository = app(\App\Http\Controllers\Knowledge\Repositories\StreamRepository::class);
                $stream = $streamRepository->getLastStream($selectedChat);
                $qualificationData = null;

                if ($stream && $stream->lead_qualification) {
                    $qualification = $stream->lead_qualification;
                    // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
                    if (is_string($qualification)) {
                        $qualification = json_decode($qualification, true);
                    }
                    if (!is_array($qualification)) {
                        $qualification = null;
                    }

                    $knowledge = $stream->knowledge_base ?? [];
                    // O cast do modelo já retorna como array, mas mantém compatibilidade com dados antigos
                    if (is_string($knowledge)) {
                        $knowledge = json_decode($knowledge, true);
                    }
                    if (!is_array($knowledge)) {
                        $knowledge = [];
                    }
                    $knowledgeQualification = $knowledge['qualificacao_lead'] ?? null;

                    $qualificationData = [
                        'qualified' => $qualification['qualified'] ?? false,
                        'score' => $qualification['score'] ?? 0,
                        'reasons' => $qualification['reasons'] ?? [],
                        'analysis' => $qualification['analysis'] ?? '',
                        'confidence' => $qualification['confidence'] ?? null,
                        'indicators' => $qualification['indicators'] ?? [],
                        'criteria_met' => $qualification['criteria_met'] ?? [],
                        'data_analise' => $knowledgeQualification['data_analise'] ?? null,
                        'tipo_analise' => $knowledgeQualification['tipo_analise'] ?? 'generica',
                        'criterios_usados' => $knowledgeQualification['criterios_usados'] ?? null,
                        'versao_analise' => $knowledgeQualification['versao_analise'] ?? '1.0',
                    ];

                    $conversationLog = $stream->conversation_log ?? [];
                    if (is_string($conversationLog)) {
                        $conversationLog = json_decode($conversationLog, true);
                    }
                    $history = $conversationLog['history'] ?? [];
                    $qualificationData['total_messages'] = count($history);
                    $qualificationData['last_qualification_count'] = $stream->last_qualification_count ?? 0;
                    $qualificationData['last_qualification_analysis'] = $stream->last_qualification_analysis ?? null;
                }
            @endphp
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"
                style="z-index: 1056; position: relative;">
                <div class="modal-content" style="z-index: 1056; position: relative;"
                    onclick="event.stopPropagation();">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="me-2 ri-user-search-line"></i>
                            Detalhes da Qualificação - {{ $selectedChat->name }}
                        </h5>
                        <button type="button" class="btn-close" wire:click="closeDetailsModal"
                            data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        @if ($qualificationData)
                            <!-- Informações do Chat -->
                            <div class="mb-3 card">
                                <div class="bg-light card-header">
                                    <h6 class="mb-0"><i class="me-2 ri-chat-3-line"></i>Informações do Chat</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Nome:</strong>
                                                {{ $selectedChat->name ?? 'N/A' }}</p>
                                            <p class="mb-2"><strong>Telefone:</strong>
                                                {{ preg_replace('/@.*$/', '', $selectedChat->phone) }}
                                            </p>
                                            <p class="mb-2"><strong>Agente:</strong>
                                                {{ $selectedChat->agent ? $selectedChat->agent->name : 'N/A' }}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Total de Mensagens:</strong>
                                                {{ $qualificationData['total_messages'] ?? 0 }}</p>
                                            <p class="mb-2"><strong>Mensagens na Última Análise:</strong>
                                                {{ $qualificationData['last_qualification_count'] ?? 0 }}</p>
                                            <p class="mb-2"><strong>Conversão:</strong>
                                                @php
                                                    $isConverted =
                                                        $selectedChat->conversion === true ||
                                                        $selectedChat->conversion === 'true' ||
                                                        $selectedChat->conversion === 1;
                                                @endphp
                                                @if ($isConverted)
                                                    <span class="bg-success badge">Sim</span>
                                                @else
                                                    <span class="bg-secondary badge">Não</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Score e Status -->
                            <div class="mb-3 card">
                                <div class="bg-light card-header">
                                    <h6 class="mb-0"><i class="me-2 ri-star-line"></i>Score e Qualificação</h6>
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

                            <!-- Motivos -->
                            @if (!empty($qualificationData['reasons']))
                                <div class="mb-3 card">
                                    <div class="bg-light card-header">
                                        <h6 class="mb-0"><i class="me-2 ri-list-check"></i>Motivos da Qualificação
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="mb-0 list-unstyled">
                                            @foreach ($qualificationData['reasons'] as $reason)
                                                <li class="mb-2">
                                                    <i class="me-2 text-success ri-checkbox-circle-line"></i>
                                                    {{ $reason }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            @endif

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

                            <!-- Indicadores -->
                            @if (!empty($qualificationData['indicators']))
                                <div class="mb-3 card">
                                    <div class="bg-light card-header">
                                        <h6 class="mb-0"><i class="me-2 ri-bar-chart-line"></i>Indicadores</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            @foreach ($qualificationData['indicators'] as $key => $value)
                                                <div class="mb-2 col-md-4">
                                                    <strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong>
                                                    <span
                                                        class="bg-info-transparent ms-2 badge">{{ $value }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- Critérios Atendidos -->
                            @if (!empty($qualificationData['criteria_met']))
                                <div class="mb-3 card">
                                    <div class="bg-light card-header">
                                        <h6 class="mb-0"><i class="me-2 ri-check-double-line"></i>Critérios de
                                            Closing</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            @foreach ($qualificationData['criteria_met'] as $criterion => $met)
                                                <div class="mb-2 col-md-6">
                                                    @if ($met)
                                                        <i class="me-2 text-success ri-checkbox-circle-line"></i>
                                                    @else
                                                        <i class="me-2 text-danger ri-close-circle-line"></i>
                                                    @endif
                                                    <strong>{{ $criterion }}:</strong>
                                                    <span
                                                        class="badge bg-{{ $met ? 'success' : 'danger' }}-transparent">
                                                        {{ $met ? 'Atendido' : 'Não Atendido' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- Informações da Análise -->
                            <div class="card">
                                <div class="bg-light card-header">
                                    <h6 class="mb-0"><i class="me-2 ri-information-line"></i>Informações da Análise
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2"><strong>Tipo de Análise:</strong>
                                                {{ $qualificationData['tipo_analise'] ?? 'Genérica' }}</p>
                                            <p class="mb-2"><strong>Versão:</strong>
                                                {{ $qualificationData['versao_analise'] ?? '1.0' }}</p>
                                        </div>
                                        <div class="col-md-6">
                                            @if (isset($qualificationData['data_analise']))
                                                <p class="mb-2"><strong>Data da Análise:</strong>
                                                    {{ \Carbon\Carbon::parse($qualificationData['data_analise'])->format('d/m/Y H:i:s') }}
                                                </p>
                                            @endif
                                            @if (isset($qualificationData['last_qualification_analysis']))
                                                <p class="mb-2"><strong>Última Análise:</strong>
                                                    {{ \Carbon\Carbon::parse($qualificationData['last_qualification_analysis'])->format('d/m/Y H:i:s') }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                    @if (!empty($qualificationData['criterios_usados']))
                                        <div class="mt-3">
                                            <strong>Critérios Utilizados:</strong>
                                            <div class="bg-light mt-2 p-3 rounded">
                                                @if (is_string($qualificationData['criterios_usados']))
                                                    {{ $qualificationData['criterios_usados'] }}
                                                @else
                                                    <pre class="mb-0">{{ json_encode($qualificationData['criterios_usados'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="py-5 text-center">
                                <i class="text-muted ri-information-line fs-48"></i>
                                <p class="mt-3 mb-0 text-muted">Este chat ainda não possui análise de qualificação.</p>
                                <button class="mt-3 btn btn-primary"
                                    wire:click="forceAnalysis('{{ $selectedChat->uuid }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="forceAnalysis('{{ $selectedChat->uuid }}')"
                                    @if ($isProcessing && $processingChatId === $selectedChat->uuid) disabled @endif>
                                    <span wire:loading.remove
                                        wire:target="forceAnalysis('{{ $selectedChat->uuid }}')">
                                        <i class="me-2 ri-refresh-line"></i>Realizar Análise Agora
                                    </span>
                                    <span wire:loading wire:target="forceAnalysis('{{ $selectedChat->uuid }}')">
                                        <span class="me-2 spinner-border spinner-border-sm" role="status"
                                            aria-hidden="true"></span>
                                        Processando...
                                    </span>
                                </button>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeDetailsModal"
                            wire:loading.attr="disabled" wire:target="closeDetailsModal,forceAnalysis">Fechar</button>
                        @if ($qualificationData)
                            <button type="button" class="btn btn-primary"
                                wire:click="forceAnalysis('{{ $selectedChat->uuid }}')" wire:loading.attr="disabled"
                                wire:target="forceAnalysis('{{ $selectedChat->uuid }}')"
                                @if ($isProcessing && $processingChatId === $selectedChat->uuid) disabled @endif>
                                <span wire:loading.remove wire:target="forceAnalysis('{{ $selectedChat->uuid }}')">
                                    <i class="me-2 ri-refresh-line"></i>Forçar Nova Análise
                                </span>
                                <span wire:loading wire:target="forceAnalysis('{{ $selectedChat->uuid }}')">
                                    <span class="me-2 spinner-border spinner-border-sm" role="status"
                                        aria-hidden="true"></span>
                                    Processando...
                                </span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script>
            document.addEventListener('livewire:init', function() {
                // Adicionar classe modal-open ao body quando modal estiver aberto
                Livewire.hook('morph.updated', () => {
                    setTimeout(() => {
                        const modalElement = document.getElementById('qualificationDetailsModal');
                        if (modalElement && modalElement.classList.contains('show')) {
                            if (!document.body.classList.contains('modal-open')) {
                                document.body.classList.add('modal-open');
                                document.body.style.overflow = 'hidden';
                                document.body.style.paddingRight = '0px';
                            }
                        } else {
                            if (document.body.classList.contains('modal-open')) {
                                document.body.classList.remove('modal-open');
                                document.body.style.overflow = '';
                                document.body.style.paddingRight = '';
                            }
                        }
                    }, 50);
                });

                // Limpar estado quando modal for fechado pelo backdrop
                document.addEventListener('click', function(e) {
                    const backdrop = e.target;
                    // Verificar se clicou diretamente no backdrop (não no conteúdo do modal)
                    if (backdrop.classList && backdrop.classList.contains('modal-backdrop') &&
                        !backdrop.closest('.modal-content') &&
                        !backdrop.closest('.modal-dialog')) {
                        const modal = document.getElementById('qualificationDetailsModal');
                        if (modal && modal.contains(backdrop)) {
                            @this.set('showDetailsModal', false);
                            @this.set('selectedChat', null);
                        }
                    }
                });

                // Listener para quando análise for concluída
                Livewire.on('analysis-completed', (event) => {
                    // Se o modal estiver aberto, atualiza os dados
                    const chatId = event.detail && event.detail.chatId ? event.detail.chatId : event.chatId;
                    if (@this.showDetailsModal && @this.selectedChat && chatId) {
                        // Força atualização do componente recarregando os dados
                        setTimeout(() => {
                            @this.call('showQualificationDetails', chatId);
                        }, 500);
                    }
                });

                // Previne múltiplos cliques rápidos
                let lastClickTime = 0;
                document.addEventListener('click', function(e) {
                    const button = e.target.closest('[wire\\:click*="forceAnalysis"]');
                    if (button) {
                        const now = Date.now();
                        if (now - lastClickTime < 1000) { // 1 segundo de cooldown
                            e.preventDefault();
                            e.stopPropagation();
                            return false;
                        }
                        lastClickTime = now;
                    }
                });
            });
        </script>
    @endpush
</div>
