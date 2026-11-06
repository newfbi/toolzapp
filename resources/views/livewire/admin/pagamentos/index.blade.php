<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Painel de Pagamentos',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Pagamentos',
            ],
        ],
    ])
    <!--Row-->
    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-md-12">
            <div class="mb-4 card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <header class="mb-0 card-header-title">Detalhes dos Pagamentos</header>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input type="text" class="form-control" placeholder="Nome, status, tipo ou email"
                                wire:model.debounce.300ms="filterSearch">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Valor</th>
                                    <th>Tipo</th>
                                    <th>Status</th>
                                    <th>Vencimento</th>
                                    <th>Data</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pagamentos as $pagamento)
                                    <tr>
                                        <td>{{ $pagamento->user->name }}</td>
                                        <td>{{ $pagamento->valor }}</td>
                                        <td>{{ $pagamento->tipo }}</td>
                                        <td>
                                            <span
                                                class="badge badge-{{ $pagamento->status == 'pendente' || $pagamento->status == 'aberto' ? 'warning' : ($pagamento->status == 'pago' || $pagamento->status == 'creditado' ? 'success' : 'danger') }}">
                                                {{ $pagamento->status }}
                                            </span>
                                        </td>
                                        <td>{{ date('d/m/Y', strtotime($pagamento->validade)) }}</td>
                                        <td>{{ date('d/m/Y', strtotime($pagamento->updated_at)) }}</td>
                                        <td>
                                            @if ($pagamento->status == 'aberto' || $pagamento->status == 'pendente')
                                                <button class="btn btn-primary btn-sm"
                                                    wire:click="$dispatch('triggerConfirm', {id: '{{ $pagamento->uuid }}'})">
                                                    <i class="fa fa-check"></i> Liberar
                                                </button>
                                                <button class="btn btn-danger btn-sm"
                                                    wire:click="$dispatch('triggerCancel', {id: '{{ $pagamento->uuid }}'})">
                                                    <i class="fa fa-check"></i> Cancelar
                                                </button>
                                            @elseif($pagamento->status == 'pago' || $pagamento->status == 'creditado')
                                                <button class="btn btn-success btn-sm">
                                                    <i class="fa fa-check"></i> Pedido Pago
                                                </button>
                                            @elseif($pagamento->status == 'cancelado')
                                                <button class="btn btn-danger btn-sm">
                                                    <i class="fa fa-check"></i> Cancelado
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $pagamentos->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row end -->
</div>

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function registerPaymentActions() {
            Livewire.on('triggerConfirm', function (event) {
                Swal.fire({
                    title: 'Liberar pagamento?',
                    text: 'O pagamento será processado e o usuário receberá os créditos/plano correspondente.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#0d6efd',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sim, liberar',
                    cancelButtonText: 'Cancelar',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        Livewire.dispatch('detachSendPayment', {
                            id: event.id,
                        });
                    }
                });
            });

            Livewire.on('triggerCancel', function (event) {
                Swal.fire({
                    title: 'Cancelar pagamento?',
                    text: 'Esta ação não poderá ser desfeita.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sim, cancelar',
                    cancelButtonText: 'Voltar',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        Livewire.dispatch('detachCancelPayment', {
                            id: event.id,
                        });
                    }
                });
            });
        }

        if (window.Livewire) {
            registerPaymentActions();
        } else {
            document.addEventListener('livewire:init', registerPaymentActions, { once: true });
        }
    </script>
@endpush
