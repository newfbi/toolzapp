<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Planos',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('admin')], ['label' => 'Planos']],
    ])

    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-12">
            <div class="card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <h4 class="mb-0 card-title">Painel de Planos</h4>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input type="text" class="form-control" placeholder="Tipo, créditos ou preço"
                                wire:model.debounce.300ms="filterSearch">
                        </div>
                        <button class="btn btn-primary" wire:click="showCreateModal">Novo Plano</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Tipo</th>
                                    <th>Créditos</th>
                                    <th>Preço</th>
                                    <th>Comissão</th>
                                    <th>Indicação</th>
                                    <th>Status</th>
                                    <th>Regras</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($plans as $plan)
                                    <tr>
                                        <td>{{ $plan->name }}</td>
                                        <td>{{ $plan->tipo }}</td>
                                        <td>{{ $plan->credits }}</td>
                                        <td>{{ isset($plan->price) ? 'R$ ' . number_format($plan->price, 2, ',', '.') : '--' }}
                                        </td>
                                        <td>
                                            @php
                                                $st = $plan->status ?? null;
                                                $cls =
                                                    $st === 'enabled'
                                                        ? 'bg-success'
                                                        : ($st === 'disabled'
                                                            ? 'bg-secondary'
                                                            : ($st === 'canceled'
                                                                ? 'bg-danger'
                                                                : 'bg-light'));
                                            @endphp
                                            <span class="badge {{ $cls }}">{{ $st ?? '--' }}</span>
                                        </td>
                                        <td>{{ isset($plan->commission) ? number_format($plan->commission, 2, ',', '.') . '%' : '--' }}
                                        </td>
                                        <td>{{ isset($plan->indication) ? number_format($plan->indication, 2, ',', '.') . '%' : '--' }}
                                        </td>
                                        <td>
                                            @php
                                                $rolesRaw = $plan->roles ?? null;
                                                $rolesList = is_array($rolesRaw)
                                                    ? $rolesRaw
                                                    : (is_string($rolesRaw)
                                                        ? (json_decode($rolesRaw, true) ?:
                                                        [])
                                                        : []);
                                            @endphp
                                            <div class="d-flex flex-wrap gap-1">
                                                @forelse ($rolesList as $r)
                                                    <span
                                                        class="bg-info badge">{{ is_array($r) ? $r['name'] ?? ($r['value'] ?? '') : $r }}</span>
                                                @empty
                                                    <span class="text-muted">--</span>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn icon-btn-sm btn-light-warning"
                                                wire:click="showEditModal({{ $plan->id }})" title="Editar"><i
                                                    class="bi bi-pencil"></i></button>
                                            <button class="btn icon-btn-sm btn-light-danger"
                                                onclick="window.deletePlan({{ $plan->id }})" title="Excluir"><i
                                                    class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $plans->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createPlanModal" wire:ignore.self>
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Novo Plano</h5>
                    <button type="button" class="btn-close" id="addBoardBtn-close" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit="createPlan">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Nome</label>
                                <input type="text" class="form-control" wire:model="name"
                                    placeholder="Ex.: Plano Basic">
                            </div>
                            <div class="col-md-6">
                                <label>Créditos</label>
                                <input type="number" class="form-control" wire:model="credits" placeholder="Ex.: 100">
                            </div>
                            <div class="col-md-6">
                                <label>Preço</label>
                                <input type="number" step="0.01" class="form-control" wire:model="price"
                                    placeholder="Ex.: 99.90">
                            </div>
                            <div class="col-md-6">
                                <label>Comissão (%)</label>
                                <input type="number" step="0.01" class="form-control" wire:model="commission"
                                    placeholder="Ex.: 10">
                            </div>
                            <div class="col-md-6">
                                <label>Indicação (%)</label>
                                <input type="number" step="0.01" class="form-control" wire:model="indication"
                                    placeholder="Ex.: 5">
                            </div>
                            <div class="col-md-6">
                                <label>Mensalidade (dias)</label>
                                <input type="number" class="form-control" wire:model="mensalidade"
                                    placeholder="Ex.: 30">
                            </div>
                            <div class="col-md-6">
                                <label>Agentes</label>
                                <input type="number" class="form-control" wire:model="agentes" placeholder="Ex.: 3">
                            </div>
                            <div class="col-md-6">
                                <label>Instâncias</label>
                                <input type="number" class="form-control" wire:model="instances" placeholder="Ex.: 1">
                            </div>
                            <div class="col-md-6">
                                <label>Membros</label>
                                <input type="number" class="form-control" wire:model="team_members"
                                    placeholder="Ex.: 5">
                            </div>
                            <div class="col-md-6">
                                <label>Imagem (URL)</label>
                                <input type="text" class="form-control" wire:model="img"
                                    placeholder="URL da imagem">
                            </div>
                            <div class="col-md-6">
                                <label>Status</label>
                                <select class="form-control" wire:model="status">
                                    <option value="enabled">Ativo</option>
                                    <option value="disabled">Desativado</option>
                                    <option value="canceled">Cancelado</option>
                                </select>
                            </div>
                            <div class="col-md-6" wire:ignore>
                                <label>Regras (roles)</label>
                                <input id="rolesTagifyCreate" class="form-control tagify--custom-dropdown"
                                    placeholder="Digite uma regra">
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Criar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPlanModal" wire:ignore.self>
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Plano</h5>
                    <button type="button" class="btn-close" id="addBoardBtn-close" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form wire:submit="updatePlan">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Nome</label>
                                <input type="text" class="form-control" wire:model="name">
                            </div>
                            <div class="col-md-6">
                                <label>Créditos</label>
                                <input type="number" class="form-control" wire:model="credits">
                            </div>
                            <div class="col-md-6">
                                <label>Preço</label>
                                <input type="number" step="0.01" class="form-control" wire:model="price">
                            </div>
                            <div class="col-md-6">
                                <label>Comissão (%)</label>
                                <input type="number" step="0.01" class="form-control" wire:model="commission">
                            </div>
                            <div class="col-md-6">
                                <label>Indicação (%)</label>
                                <input type="number" step="0.01" class="form-control" wire:model="indication">
                            </div>
                            <div class="col-md-6">
                                <label>Mensalidade (dias)</label>
                                <input type="number" class="form-control" wire:model="mensalidade">
                            </div>
                            <div class="col-md-6">
                                <label>Agentes</label>
                                <input type="number" class="form-control" wire:model="agentes">
                            </div>
                            <div class="col-md-6">
                                <label>Instâncias</label>
                                <input type="number" class="form-control" wire:model="instances">
                            </div>
                            <div class="col-md-6">
                                <label>Membros</label>
                                <input type="number" class="form-control" wire:model="team_members">
                            </div>
                            <div class="col-md-6">
                                <label>Imagem (URL)</label>
                                <input type="text" class="form-control" wire:model="img">
                            </div>
                            <div class="col-md-6">
                                <label>Status</label>
                                <select class="form-control" wire:model="status">
                                    <option value="enabled">Ativo</option>
                                    <option value="disabled">Desativado</option>
                                    <option value="canceled">Cancelado</option>
                                </select>
                            </div>
                            <div class="col-md-6" wire:ignore>
                                <label>Regras (roles)</label>
                                <input id="rolesTagifyEdit" class="form-control tagify--custom-dropdown"
                                    placeholder="Digite uma regra">
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Atualizar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@section('css')
    <link rel="stylesheet" href="assets/libs/@yaireo/tagify/tagify.css">
    <style>
        .tagify--custom-dropdown .tagify__input:empty::before {
            color: #9aa0a6 !important;
        }

        .tagify--custom-dropdown::placeholder {
            color: #9aa0a6 !important;
        }

        .tagify.form-control {
            height: 40px;
            margin-bottom: 6px;
        }
    </style>
@endsection

@push('scripts')
    <script src="assets/libs/@yaireo/tagify/tagify.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.deletePlan = function(id) {
            Swal.fire({
                title: 'Você tem certeza?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sim, deletar!',
                cancelButtonText: 'Cancelar',
            }).then((result) => {
                if (result.isConfirmed) {
                    Livewire.dispatch('deletePlan', {
                        id: id
                    });
                }
            });
        };

        (function() {
            function registerHandlers() {
                Livewire.on('show_create_plan_modal', function() {
                    var modal = new bootstrap.Modal(document.getElementById('createPlanModal'));
                    modal.show();
                    setTimeout(function() {
                        var el = document.getElementById('rolesTagifyCreate');
                        if (el) {
                            if (window.__createTagify) {
                                window.__createTagify.destroy();
                            }
                            var whitelist = @json(collect($this->allRoles)->pluck('name')->values()->all());
                            window.__createTagify = new Tagify(el, {
                                whitelist: whitelist,
                                enforceWhitelist: true,
                                dropdown: {
                                    enabled: 1,
                                    maxItems: 50,
                                    position: 'input',
                                    fuzzySearch: true,
                                    highlightFirst: true,
                                    closeOnSelect: false,
                                    showOnFocus: true
                                }
                            });

                            function onChange() {
                                var values = window.__createTagify.value.map(function(v) {
                                    return v.value;
                                });
                                var cmp = Livewire.find('{{ $this->getId() }}');
                                if (cmp) {
                                    cmp.set('roles', values);
                                }
                            }
                            window.__createTagify.on('add', onChange).on('remove', onChange).on(
                                'change', onChange);
                        }
                    }, 50);
                });
                Livewire.on('hide_create_plan_modal', function() {
                    var modalEl = document.getElementById('createPlanModal');
                    var modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                });
                Livewire.on('show_edit_plan_modal', function() {
                    var modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
                    modal.show();
                    setTimeout(function() {
                        var el = document.getElementById('rolesTagifyEdit');
                        if (el) {
                            if (window.__editTagify) {
                                window.__editTagify.destroy();
                            }
                            var whitelist = @json(collect($this->allRoles)->pluck('name')->values()->all());
                            window.__editTagify = new Tagify(el, {
                                whitelist: whitelist,
                                enforceWhitelist: true,
                                dropdown: {
                                    enabled: 1,
                                    maxItems: 50,
                                    position: 'input',
                                    fuzzySearch: true,
                                    highlightFirst: true,
                                    closeOnSelect: false,
                                    showOnFocus: true
                                }
                            });
                            var initial = @json($roles ?? []);
                            if (Array.isArray(initial)) {
                                window.__editTagify.addTags(initial);
                            }

                            function onChange() {
                                var values = window.__editTagify.value.map(function(v) {
                                    return v.value;
                                });
                                var cmp = Livewire.find('{{ $this->getId() }}');
                                if (cmp) {
                                    cmp.set('roles', values);
                                }
                            }
                            window.__editTagify.on('add', onChange).on('remove', onChange).on('change',
                                onChange);
                        }
                    }, 50);
                });
                Livewire.on('hide_edit_plan_modal', function() {
                    var modalEl = document.getElementById('editPlanModal');
                    var modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                });
            }
            if (window.Livewire && Livewire.on) {
                registerHandlers();
            } else {
                window.addEventListener('livewire:init', registerHandlers);
            }
        })();
    </script>
@endpush
