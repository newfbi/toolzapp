<div class="mt-3 container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Lista de Usuários',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('dashboard'),
            ],
            [
                'label' => 'Leads',
            ],
        ],
    ])

    <div class="row row-sm">
        @include('layouts.includes.errors-and-messages')

        <!-- Cards de estatísticas -->
        <div class="mb-3 col-xl-3 col-md-6 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="mb-0 text-muted">Total de Contatos</p>
                            <h4 class="mt-1 mb-0">{{ $totalContacts }}</h4>
                        </div>
                        <div class="avatar-sm bg-primary-subtle rounded">
                            <i class="fs-24 text-primary ri-contacts-book-line"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mb-3 col-xl-3 col-md-6 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="mb-0 text-muted">Ativos</p>
                            <h4 class="mt-1 mb-0 text-success">{{ $activeContacts }}</h4>
                        </div>
                        <div class="avatar-sm bg-success-subtle rounded">
                            <i class="fs-24 text-success ri-user-follow-line"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mb-3 col-xl-3 col-md-6 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="mb-0 text-muted">Total enviadas</p>
                            <h4 class="mt-1 mb-0 text-info">{{ $totalSentMessages }}</h4>
                        </div>
                        <div class="avatar-sm bg-info-subtle rounded">
                            <i class="fs-24 text-info ri-send-plane-line"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mb-3 col-xl-3 col-md-6 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="mb-0 text-muted">Inativos</p>
                            <h4 class="mt-1 mb-0 text-secondary">{{ $inactiveContacts }}</h4>
                        </div>
                        <div class="avatar-sm bg-secondary-subtle rounded">
                            <i class="fs-24 text-secondary ri-user-unfollow-line"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Seção de Inclusão e Importação de Leads -->
        <div class="col-lg-8 col-md-7 mb-3">
            <div class="card h-100 mb-0">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <h5 class="mb-0 card-title"><i class="me-2 ri-upload-cloud-line text-primary"></i>Importar Contatos via CSV ou PDF</h5>
                    <button type="button" class="btn btn-link btn-sm text-decoration-none" data-bs-toggle="collapse"
                        data-bs-target="#csvInstructions" aria-expanded="false">
                        <i class="me-1 ri-information-line"></i>Instruções e modelo
                    </button>
                </div>
                <div class="card-body">
                    <div class="collapse" id="csvInstructions">
                        <div class="alert alert-info small">
                                <strong>Formato esperado do arquivo (primeira linha = cabeçalho):</strong>
                                <code class="d-block my-2 p-2 bg-light text-dark rounded">nome,email,telefone,status,opt_in,sms_opt_in<br>João Silva,joao@email.com,5511999998888,ativo,sim,nao</code>
                                <ul class="ps-3 mb-2">
                                    <li>Aceita separador vírgula (,) ou ponto e vírgula (;)</li>
                                    <li>Cabeçalhos aceitos: nome, email, telefone/celular/whatsapp, status, opt_in e sms_opt_in</li>
                                    <li>O opt-in de WhatsApp e SMS é separado. Contatos sem a autorização do canal escolhido ficam bloqueados.</li>
                                <li>Telefones com DDD: fixo (10 dígitos) ou celular (11 dígitos).</li>
                                <li>Limite: 5.000 contatos por arquivo (até 5 MB)</li>
                            </ul>
                            <a class="fw-bold" download="modelo_contatos.csv"
                                href="data:text/csv;charset=utf-8,nome%2Cemail%2Ctelefone%2Cstatus%2Copt_in%2Csms_opt_in%0AJo%C3%A3o%20Silva%2Cjoao%40email.com%2C5511999998888%2Cativo%2Csim%2Cnao%0AMaria%20Santos%2Cmaria%40email.com%2C5521988887777%2Cativo%2Cnao%2Csim">
                                <i class="me-1 ri-download-2-line"></i>Baixar modelo CSV
                            </a>
                        </div>
                    </div>

                    <form wire:submit="importFile" class="row g-2 align-items-end">
                        <div class="col-sm-8">
                            <label for="csvFileInput" class="form-label small text-muted">Arquivo CSV ou PDF</label>
                            <input type="file" wire:model="csvFile"
                                accept=".csv,text/csv,text/plain,.pdf,application/pdf"
                                class="form-control @error('csvFile') is-invalid @enderror" id="csvFileInput">
                            @error('csvFile')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div wire:loading wire:target="csvFile" class="mt-1 text-muted small">
                                <i class="spinner-border spinner-border-sm me-1"></i>Enviando arquivo...
                            </div>
                        </div>
                        <div class="col-sm-4 d-grid">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                                wire:target="csvFile">
                                <i class="me-1 ri-upload-2-line"></i>Importar
                            </button>
                        </div>
                    </form>

                    {{-- Resumo da última importação --}}
                    @if ($importSummary)
                        <div class="mt-3 alert alert-success mb-0">
                            <button type="button" class="btn-close float-end" wire:click="clearImportSummary"
                                aria-label="Fechar"></button>
                            <h6 class="alert-heading"><i class="me-1 ri-checkbox-circle-line"></i>Importação concluída</h6>
                            <p class="mb-1">
                                <strong>{{ $importSummary['imported'] }}</strong> importado(s) ·
                                <span class="text-warning">{{ $importSummary['skipped'] }} duplicado(s)</span> ·
                                <span class="text-danger">{{ $importSummary['invalid'] }} inválido(s)</span> ·
                                <span class="text-muted">{{ $importSummary['without_opt_in'] ?? 0 }} sem opt-in WhatsApp · {{ $importSummary['without_sms_opt_in'] ?? 0 }} sem opt-in SMS</span>
                                @if (($importSummary['opt_in_updated'] ?? 0) > 0)
                                    · <span class="text-success">{{ $importSummary['opt_in_updated'] }} opt-in(s) atualizado(s)</span>
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Card de Inclusão Manual Individual -->
        <div class="col-lg-4 col-md-5 mb-3">
            <div class="card h-100 mb-0 border-primary-subtle">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 card-title"><i class="me-2 ri-user-add-line text-success"></i>Novo Lead Individual</h5>
                    <span class="badge bg-primary-subtle text-primary">Manual</span>
                </div>
                <div class="card-body d-flex flex-column justify-content-between">
                    <p class="text-muted small mb-3">
                        Cadastre rapidamente um lead de forma individual informando nome, WhatsApp e e-mail para gestão e envio direto.
                    </p>
                    <div class="d-grid">
                        <button type="button" wire:click="openCreateLeadModal" class="btn btn-success py-2 fw-semibold d-flex align-items-center justify-content-center gap-2">
                            <i class="ri-user-add-fill fs-18"></i>
                            <span>Adicionar Novo Lead</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros e Tabela de Contatos -->
        <div class="col-12">
            <div class="card">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 card-header">
                    <h5 class="mb-0 card-title"><i class="me-2 ri-list-check-2"></i>Meus Contatos</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <input type="text" wire:model.debounce.300ms="search" class="form-control form-control-sm"
                            placeholder="Buscar nome, e-mail ou telefone..." style="width: 220px;">
                        <select wire:model="filterStatus" class="form-select form-select-sm" style="width: 130px;">
                            <option value="">Todos Status</option>
                            <option value="active">Ativos</option>
                            <option value="inactive">Inativos</option>
                        </select>
                        <button type="button" wire:click="selectAllContacts" class="btn btn-sm btn-outline-primary">
                            <i class="me-1 ri-checkbox-multiple-line"></i>Selecionar todas
                        </button>
                        <button type="button" wire:click="toggleSelectPage" class="btn btn-sm btn-outline-secondary">
                            <i class="me-1 ri-checkbox-multiple-line"></i>Página
                        </button>
                        <button type="button" wire:click="selectAllFiltered" class="btn btn-sm btn-outline-primary">
                            <i class="me-1 ri-filter-3-line"></i>Todos do filtro
                        </button>
                        @if (count($selected) > 0 || $selectAllFiltered || $selectAllEntireList)
                            <button type="button" wire:click="clearSelection" class="btn btn-sm btn-outline-danger">
                                <i class="me-1 ri-close-circle-line"></i>Limpar
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    {{-- Barra de disparo em massa (aparece com seleção ativa) --}}
                    @php
                        $scopeCount = $this->dispatchScopeCount;
                    @endphp
                    @if ($scopeCount > 0)
                        <div class="mb-3 p-3 rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-3 user-list-dispatch-banner">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-list-dispatch-icon-box">
                                    <i class="ri-send-plane-2-fill"></i>
                                </div>
                                <div>
                                    @if ($selectAllEntireList && count($selected) === 0)
                                        <div class="user-list-dispatch-title">
                                            <strong class="user-list-dispatch-count">{{ $scopeCount }}</strong> contatos de toda a lista selecionados
                                        </div>
                                        <small class="user-list-dispatch-subtitle text-muted">Todos os contatos serão preparados para disparo</small>
                                    @elseif ($selectAllFiltered && count($selected) === 0)
                                        <div class="user-list-dispatch-title">
                                            <strong class="user-list-dispatch-count">{{ $scopeCount }}</strong> contatos no filtro atual selecionados
                                        </div>
                                        <small class="user-list-dispatch-subtitle text-muted">Todos os contatos filtrados serão preparados para disparo</small>
                                    @else
                                        <div class="user-list-dispatch-title">
                                            <strong class="user-list-dispatch-count">{{ $scopeCount }}</strong> contato(s) selecionado(s) para disparo
                                        </div>
                                        <small class="user-list-dispatch-subtitle text-muted">Pronto para iniciar envio de mensagens</small>
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" wire:click="clearSelection" class="btn btn-sm btn-outline-danger px-3">
                                    <i class="ri-close-circle-line me-1"></i>Limpar
                                </button>
                                <button type="button" wire:click="openDispatchModal" class="btn btn-sm btn-success px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm user-list-dispatch-btn"
                                    title="Abrir modal de disparo">
                                    <i class="ri-send-plane-2-line fs-15"></i>
                                    <span>Disparar Mensagem</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap align-middle mb-0 user-list-table">
                            <thead>
                                <tr>
                                    <th style="width: 36px;"></th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Telefone</th>
                                    <th>Status</th>
                                    <th>Importado em</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lists as $contact)
                                    <tr @class(['user-list-row-selected' => in_array($contact->uuid, $selected)])>
                                        <td>
                                            <input type="checkbox" wire:model.live="selected"
                                                value="{{ $contact->uuid }}" class="user-list-checkbox form-check-input" aria-label="Selecionar {{ $contact->name }}">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-xs bg-primary-subtle rounded-circle me-2 d-flex align-items-center justify-content-center">
                                                    <small class="fw-bold text-primary">{{ strtoupper(mb_substr($contact->name, 0, 1)) }}</small>
                                                </div>
                                                <span class="me-1">{{ $contact->name }}</span>
                                                @if ($info = $receivedCounts[$contact->uuid] ?? null)
                                                    <span class="badge bg-success-subtle text-success"
                                                        title="Já recebeu {{ $info['total'] }} mensagem(ns){{ $info['last_sent_at'] ? ' — última em ' . \Carbon\Carbon::parse($info['last_sent_at'])->format('d/m H:i') : '' }}">
                                                        <i class="ri-check-double-line me-1"></i>{{ $info['total'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td>{{ $contact->email ?? '--' }}</td>
                                        <td>
                                            @php
                                                $p = (string) $contact->phone;
                                                if (strlen($p) === 11) {
                                                    $phoneFmt = '(' . substr($p, 0, 2) . ') ' . substr($p, 2, 5) . '-' . substr($p, 7);
                                                } elseif (strlen($p) === 10) {
                                                    $phoneFmt = '(' . substr($p, 0, 2) . ') ' . substr($p, 2, 4) . '-' . substr($p, 6);
                                                } else {
                                                    $phoneFmt = $p; // Internacional ou formato não-BR: mostra como está
                                                }
                                            @endphp
                                            {{ $contact->phone ? $phoneFmt : '--' }}
                                        </td>
                                        <td>
                                            <button wire:click="toggleStatus('{{ $contact->uuid }}')"
                                                class="badge border-0 {{ $contact->status === 'active' ? 'bg-success' : 'bg-secondary' }}"
                                                title="Clique para alterar o status">
                                                {{ $contact->status === 'active' ? 'Ativo' : 'Inativo' }}
                                            </button>
                                        </td>
                                        <td><small class="text-muted">{{ $contact->created_at->format('d/m/Y H:i') }}</small></td>
                                        <td class="text-end">
                                            @if ($contact->phone)
                                                <button type="button"
                                                    wire:click="sendSingle('{{ $contact->uuid }}')"
                                                    class="btn btn-soft-success btn-sm me-1"
                                                    title="Enviar mensagem para este contato">
                                                    <i class="ri-send-plane-line"></i>
                                                </button>
                                            @endif
                                            <button type="button"
                                                wire:click="showDeleteConfirm('{{ $contact->uuid }}', '{{ $contact->name }}')"
                                                class="btn btn-soft-danger btn-sm" title="Excluir contato">
                                                <i class="ri-delete-bin-line"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-5 text-center">
                                            <i class="display-4 text-muted ri-contacts-book-line"></i>
                                            <h5 class="mt-3">Nenhum contato encontrado</h5>
                                            <p class="text-muted">Importe um arquivo CSV acima para começar a gerenciar seus leads.</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        {{ $lists->links() }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de disparo -->
        <div class="modal fade" id="whatsappDispatchModal" tabindex="-1" wire:ignore.self>
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="me-2 ri-send-plane-fill text-success"></i>Disparar
                            Mensagem</h5>
                        <button type="button" class="btn-close" wire:click="closeDispatchModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-success small mb-3">
                            <i class="me-1 ri-information-line"></i>
                            <strong>{{ $this->dispatchScopeCount }}</strong> contato(s) foram selecionados.
                            @if ($dispatchChannel === 'whatsapp')
                                O WhatsApp usa somente contatos com opt-in verdadeiro, intervalo fixo de 60s, máximo de 15/hora e descanso das 00h às 08h.
                            @else
                                O SMS usa somente contatos com opt-in SMS verdadeiro, até 5 partes por mensagem e lotes processados pela {{ $smsProviderLabel }}.
                            @endif
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Canal de envio*</label>
                                <select wire:model.live="dispatchChannel" class="form-select">
                                    <option value="whatsapp">WhatsApp — instância conectada</option>
                                    <option value="sms">SMS — {{ $smsProviderLabel }}</option>
                                </select>
                            </div>

                            @if ($dispatchChannel === 'sms')
                            <div class="col-md-6">
                                <label class="form-label">Tema SMS*</label>
                                <select wire:model.live="dispatchSmsLayoutId"
                                    class="form-select @error('dispatchSmsLayoutId') is-invalid @enderror">
                                    <option value="">— Selecione —</option>
                                    @foreach ($smsLayouts as $layoutOption)
                                        <option value="{{ $layoutOption->uuid }}">{{ $layoutOption->name }} ({{ \Illuminate\Support\Str::length($layoutOption->message) }} caracteres)</option>
                                    @endforeach
                                </select>
                                @error('dispatchSmsLayoutId')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if (count($smsLayouts) === 0)
                                    <small class="text-muted d-block mt-1">Crie temas em SMS &gt; Temas SMS.</small>
                                @endif
                                @if ($this->alreadyReceivedCount > 0 && !$dispatchIncludeAlreadySent)
                                    <div class="alert alert-warning small py-2 px-2 mt-2 mb-0">
                                        <strong>{{ $this->alreadyReceivedCount }}</strong> contato(s) já receberam este tema SMS e serão ignorados.
                                    </div>
                                @endif
                            </div>
                            @else
                            <div class="col-md-6">
                                <label class="form-label">Mensagem pronta (layout)*</label>
                                <select wire:model.live="dispatchLayoutId"
                                    class="form-select @error('dispatchLayoutId') is-invalid @enderror">
                                    <option value="">— Selecione —</option>
                                    @foreach ($dispatchLayouts as $layoutOption)
                                        <option value="{{ $layoutOption->uuid }}">
                                            {{ $layoutOption->name }}{{ $layoutOption->hasImage() ? ' (com imagem)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('dispatchLayoutId')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if (count($dispatchLayouts) === 0)
                                    <small class="text-muted d-block mt-1">Crie layouts em WhatsApp > Mensagens
                                        Prontas.</small>
                                @endif

                                {{-- Dedupe: quantos do escopo já receberam ESTE layout --}}
                                @if ($this->alreadyReceivedCount > 0 && !$dispatchIncludeAlreadySent)
                                    <div class="alert alert-warning small py-2 px-2 mt-2 mb-0">
                                        <i class="me-1 ri-shield-check-line"></i>
                                        <strong>{{ $this->alreadyReceivedCount }}</strong> contato(s) do escopo já
                                        receberam esta mensagem e serão <strong>ignorados</strong> automaticamente.
                                    </div>
                                @endif
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Instância de envio*</label>
                                <select wire:model.live="dispatchAgentId"
                                    class="form-select @error('dispatchAgentId') is-invalid @enderror">
                                    <option value="">— Selecione —</option>
                                    @foreach ($connectedInstances as $instanceOption)
                                        <option value="{{ $instanceOption->agent_id }}">
                                            {{ $instanceOption->name ?? $instanceOption->agent?->name ?? $instanceOption->agent_id }}
                                            {{ $instanceOption->phone_number ? "({$instanceOption->phone_number})" : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('dispatchAgentId')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if (count($connectedInstances) === 0)
                                    <small class="text-danger d-block mt-1">Nenhuma instância conectada. Conecte em
                                        WhatsApp > Instâncias.</small>
                                @endif
                            </div>
                            @endif

                            <div class="col-12">
                                <div class="form-check mb-1">
                                    <input type="checkbox" wire:model.live="dispatchIncludeAlreadySent"
                                        class="form-check-input" id="dispatchIncludeAlreadySentCheck">
                                    <label class="form-check-label small" for="dispatchIncludeAlreadySentCheck">
                                        Reenviar mesmo para quem <strong>já recebeu este layout</strong>
                                        (layouts diferentes nunca são bloqueados)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" wire:model.live="dispatchConfirmOptIn"
                                        class="form-check-input @error('dispatchConfirmOptIn') is-invalid @enderror"
                                        id="dispatchOptInCheck">
                                    <label class="form-check-label small" for="dispatchOptInCheck">
                                        Confirmo que estes contatos <strong>autorizaram receber</strong> mensagens pelo canal escolhido e que o conteúdo não é spam.
                                    </label>
                                    @error('dispatchConfirmOptIn')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeDispatchModal">Cancelar</button>
                        <button type="button" wire:click="startDispatch" class="btn btn-success"
                            wire:loading.attr="disabled" wire:target="startDispatch"
                            @if (!$dispatchConfirmOptIn || ($dispatchChannel === 'sms' ? !$dispatchSmsLayoutId : (!$dispatchLayoutId || !$dispatchAgentId))) disabled @endif>
                            <i class="me-1 ri-send-plane-2-line"></i>Iniciar Disparo
                            <i class="spinner-border spinner-border-sm ms-1" wire:loading wire:target="startDispatch"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de detalhes do disparo -->
@if ($viewingDispatch)
    <div class="modal fade show d-block" id="dispatchDetailsModal" tabindex="-1"
        style="background-color: rgba(0,0,0,0.5);" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="me-2 ri-file-list-3-line text-success"></i>Disparo:
                        {{ $viewingDispatch['name'] }}
                    </h5>
                    <button type="button" class="btn-close" wire:click="closeDispatchDetails"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3 small">
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Status</span>
                            <strong>{{ strtoupper($viewingDispatch['status']) }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Enviadas</span>
                            <strong class="text-success">{{ $viewingDispatch['sent'] }}/{{ $viewingDispatch['total'] }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Entregues</span>
                            <strong class="text-info">{{ $viewingDispatch['delivered'] }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Lidas</span>
                            <strong class="text-primary">{{ $viewingDispatch['read'] }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Falhas</span>
                            <strong class="{{ $viewingDispatch['failed'] ? 'text-danger' : '' }}">{{ $viewingDispatch['failed'] }}</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block">Criado em</span>
                            <strong>{{ $viewingDispatch['created_at'] }}</strong>
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 50vh;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Contato</th>
                                    <th>Telefone</th>
                                    <th>Status</th>
                                    <th>Confirmação</th>
                                    <th>Enviado em</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($viewingDispatchItems as $item)
                                    <tr>
                                        <td>{{ $item['name'] }}</td>
                                        <td><small>{{ $item['phone'] }}</small></td>
                                        <td>
                                            @switch($item['status'])
                                                @case('sent')
                                                    <span class="badge bg-success"><i class="ri-check-line me-1"></i>Aceita</span>
                                                @break
                                                @case('pending')
                                                    <span class="badge bg-warning">Na fila</span>
                                                @break
                                                @case('failed')
                                                    <span class="badge bg-danger" title="{{ $item['error'] }}">Falhou</span>
                                                @break
                                                @default
                                                    <span class="badge bg-secondary">{{ $item['status'] }}</span>
                                            @endswitch
                                        </td>
                                        <td>
                                            @if ($item['status'] !== 'sent')
                                                <span class="text-muted">--</span>
                                            @else
                                                @switch($item['delivery_status'])
                                                    @case('read')
                                                    @case('played')
                                                        <span class="badge bg-primary"><i class="ri-check-double-line me-1"></i>Lida</span>
                                                    @break
                                                    @case('delivered')
                                                        <span class="badge bg-info text-dark"><i class="ri-check-double-line me-1"></i>Entregue</span>
                                                    @break
                                                    @case('server_ack')
                                                        <span class="badge bg-success-subtle text-success">Servidor confirmou</span>
                                                    @break
                                                    @case('accepted')
                                                        <span class="badge bg-secondary">Aguardando confirmação</span>
                                                    @break
                                                    @default
                                                        <span class="badge bg-secondary">Sem confirmação</span>
                                                @endswitch
                                            @endif
                                        </td>
                                        <td><small class="text-muted">{{ $item['sent_at'] ?? '--' }}</small></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeDispatchDetails">Fechar</button>
                </div>
            </div>
        </div>
    </div>
@endif

    {{-- Modal de Inclusão Individual de Lead --}}
    <div wire:ignore.self class="modal fade" id="createLeadModal" tabindex="-1" aria-labelledby="createLeadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="createLeadModalLabel">
                        <i class="ri-user-add-line text-success fs-20"></i>
                        <span>Cadastrar Novo Lead</span>
                    </h5>
                    <button type="button" class="btn-close" wire:click="closeCreateLeadModal" aria-label="Fechar"></button>
                </div>
                <form wire:submit="saveIndividualLead">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="newLeadNameInput" class="form-label">Nome Completo <span class="text-danger">*</span></label>
                            <input type="text" wire:model="newLeadName" class="form-control @error('newLeadName') is-invalid @enderror"
                                id="newLeadNameInput" placeholder="Ex: Maria Fernandes" autocomplete="off">
                            @error('newLeadName')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="newLeadPhoneInput" class="form-label">Telefone / WhatsApp <span class="text-danger">*</span></label>
                            <input type="text" wire:model="newLeadPhone" class="form-control @error('newLeadPhone') is-invalid @enderror"
                                id="newLeadPhoneInput" placeholder="Ex: (11) 98765-4321 ou 11987654321" autocomplete="off">
                            <small class="text-muted">Informe com DDD (10 ou 11 dígitos).</small>
                            @error('newLeadPhone')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="newLeadEmailInput" class="form-label">E-mail <span class="text-muted">(Opcional)</span></label>
                            <input type="email" wire:model="newLeadEmail" class="form-control @error('newLeadEmail') is-invalid @enderror"
                                id="newLeadEmailInput" placeholder="Ex: lead@email.com" autocomplete="off">
                            @error('newLeadEmail')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-2">
                            <label for="newLeadStatusSelect" class="form-label">Status Inicial</label>
                            <select wire:model="newLeadStatus" class="form-select @error('newLeadStatus') is-invalid @enderror" id="newLeadStatusSelect">
                                <option value="active">🟢 Ativo (Disponível para disparos)</option>
                                <option value="inactive">⚪ Inativo</option>
                            </select>
                            @error('newLeadStatus')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-check mt-3">
                            <input type="checkbox" wire:model="newLeadWhatsappOptIn" class="form-check-input" id="newLeadWhatsappOptInCheck">
                            <label class="form-check-label small" for="newLeadWhatsappOptInCheck">
                                Confirmo que este contato solicitou receber mensagens de marketing no WhatsApp.
                            </label>
                        </div>
                        <div class="form-check mt-2">
                            <input type="checkbox" wire:model="newLeadSmsOptIn" class="form-check-input" id="newLeadSmsOptInCheck">
                            <label class="form-check-label small" for="newLeadSmsOptInCheck">
                                Confirmo que este contato solicitou receber mensagens de marketing por SMS.
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeCreateLeadModal">Cancelar</button>
                        <button type="submit" class="btn btn-success d-inline-flex align-items-center gap-1" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="saveIndividualLead"><i class="ri-check-line me-1"></i>Salvar Lead</span>
                            <span wire:loading wire:target="saveIndividualLead"><i class="spinner-border spinner-border-sm me-1"></i>Salvando...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@section('js')
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.addEventListener('livewire:init', function() {

            // Modal de inclusão individual de lead
            Livewire.on('openCreateLeadModalEvent', function() {
                const modal = new bootstrap.Modal(document.getElementById('createLeadModal'));
                modal.show();
            });

            Livewire.on('closeCreateLeadModalEvent', function() {
                const el = document.getElementById('createLeadModal');
                const modal = bootstrap.Modal.getInstance(el);
                if (modal) modal.hide();
            });

            Livewire.on('leadCreated', function(message) {
                Swal.fire({
                    title: 'Sucesso!',
                    text: Array.isArray(message) ? message[0] : message,
                    icon: 'success',
                    timer: 2500,
                    showConfirmButton: false
                });
            });

            // Modal de disparo
            Livewire.on('openDispatchModalEvent', function() {
                const modal = new bootstrap.Modal(document.getElementById('whatsappDispatchModal'));
                modal.show();
            });

            Livewire.on('closeDispatchModalEvent', function() {
                const el = document.getElementById('whatsappDispatchModal');
                const modal = bootstrap.Modal.getInstance(el);
                if (modal) modal.hide();
            });

            // Confirmação de exclusão de contato
            Livewire.on('showDeleteContactConfirm', function(data) {
                const contact = data[0];

                Swal.fire({
                    title: 'Excluir Contato?',
                    text: `Tem certeza que deseja excluir "${contact.name}"? Esta ação não pode ser desfeita.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        Livewire.dispatch('deleteContact', {
                            contactId: contact.id
                        });
                    }
                });
            });

            // Feedback de exclusão
            Livewire.on('contactDeleted', function(message) {
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

@push('styles')
    <style>
        /* 🎯 Banner de Disparo */
        .user-list-dispatch-banner {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.16) 0%, rgba(5, 150, 105, 0.08) 100%);
            border: 1px solid rgba(16, 185, 129, 0.38);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.10);
            backdrop-filter: blur(8px);
            transition: all 0.3s ease;
        }

        .user-list-dispatch-icon-box {
            width: 42px;
            height: 42px;
            background: rgba(16, 185, 129, 0.22);
            color: #10b981;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            border: 1px solid rgba(16, 185, 129, 0.30);
        }

        .user-list-dispatch-title {
            color: var(--bs-body-color, #f1f5f9);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .user-list-dispatch-count {
            color: #10b981;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .user-list-dispatch-subtitle {
            font-size: 0.8rem;
            display: block;
        }

        .user-list-dispatch-btn {
            background-color: #10b981 !important;
            border-color: #10b981 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.35);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .user-list-dispatch-btn:hover {
            background-color: #059669 !important;
            border-color: #059669 !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(16, 185, 129, 0.45);
        }

        /* 📋 Linhas Selecionadas na Tabela */
        .table tbody tr.user-list-row-selected {
            background-color: rgba(99, 102, 241, 0.18) !important;
            border-left: 4px solid #6366f1 !important;
            transition: background-color 0.2s ease;
        }

        .table tbody tr.user-list-row-selected > td {
            background-color: transparent !important;
            color: inherit !important;
        }

        .table tbody tr.user-list-row-selected:hover {
            background-color: rgba(99, 102, 241, 0.26) !important;
        }

        .table tbody tr.user-list-row-selected:hover > td {
            background-color: transparent !important;
        }

        /* Checkbox */
        .user-list-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #6366f1;
        }
    </style>
@endpush
