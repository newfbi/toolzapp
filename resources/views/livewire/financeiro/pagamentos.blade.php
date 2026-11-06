<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Pagamentos e Mensalidades',
        'breadcrumbItems' => [
            ['label' => 'Home', 'url' => route('dashboard')],
            ['label' => 'Pagamentos e Mensalidades'],
        ],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-12">
            <div class="mb-4 card">
                <div class="card-header">
                    <header class="card-header-title">Lista de Pagamentos</header>
                </div>
                <div class="card-body">
                    <div class="table-box table-responsive">
                        <table class="table table-hover text-nowrap">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Data pagament</th>
                                    <th scope="col">tipo</th>
                                    <th scope="col">Valor Cobrado</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pagamentos as $pagamento)
                                    <tr wire:key="{{ $loop->index }}">
                                        <td>{{ $pagamento->uuid }}</td>
                                        <td>{{ date('d/m/Y', strtotime($pagamento->data_compra)) }}
                                        </td>
                                        <td> {{ Str::title(str_replace('_', ' ', $pagamento->tipo)) }}</td>
                                        <td><span class="text-primary">{{ $pagamento->valor }}</span></td>
                                        <td scope="row">
                                            @if ($pagamento->status == 'aberto')
                                                <p class="text-primary">Aguardando</p>
                                            @elseif($pagamento->status == 'pendente')
                                                <p class="text-warning">Pendente</p>
                                            @elseif($pagamento->status == 'pago')
                                                <p class="text-success">Pago</p>
                                            @elseif($pagamento->status == 'creditado')
                                                <p class="text-success">Creditado</p>
                                            @elseif($pagamento->status == 'cancelado')
                                                <p class="text-danger">Cancelado</p>
                                            @elseif($pagamento->status == 'falhou')
                                                <p class="text-danger">Falhou</p>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($pagamento->status == 'aberto' || $pagamento->status == 'pendente')
                                                <a href="{{ route('checkout', $pagamento->uuid) }}"
                                                    class="btn btn-warning"><i class="ti ti-credit-card"></i>
                                                    Finalizar</a>
                                                <button type="button" class="ms-2 btn btn-danger"
                                                    wire:click="requestCancel('{{ $pagamento->uuid }}')"><i
                                                        class="ti ti-creative-commons-nc"></i> Cancelar</button>
                                            @elseif($pagamento->status == 'pago' || $pagamento->status == 'creditado')
                                                <button type="button" class="btn btn-success disabled"><i
                                                        class="ti ti-checks"></i> Finalizado</button>
                                            @elseif($pagamento->status == 'cancelado' || $pagamento->status == 'falhou')
                                                <button type="button" class="btn btn-danger disabled"><i
                                                        class="ti ti-creative-commons-nc"></i> Cancelado</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <!-- Debug Info (remover em produção) -->
                    <div class="mt-2 text-muted small">
                        Páginas: {{ $page }} |
                        Itens carregados: {{ $pagamentos->count() }} |
                        Total disponível: {{ $totalCount }} |
                        Tem mais: {{ $hasMorePages ? 'Sim' : 'Não' }}
                    </div>

                    <!-- Scroll Infinito -->
                    <div class="d-flex justify-content-center mt-3">
                        @if ($hasMorePages)
                            <button wire:click="loadMore" class="btn-outline-primary btn">
                                <i class="me-1 fa fa-plus"></i>
                                Carregar Mais
                            </button>
                        @else
                            <div class="text-muted">
                                <i class="me-1 fa fa-check"></i>
                                Todos os Consultores foram carregados
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('js')
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        document.addEventListener('livewire:init', function() {
            Livewire.on('showCancelConfirm', function(items) {
                const id = items[0];
                Swal.fire({
                    title: 'Cancelar pagamento?',
                    text: 'Esta ação não pode ser desfeita.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, cancelar',
                    cancelButtonText: 'Não'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch('handleCancelPayment', id);
                    }
                });
            });

            // Evento para mostrar resultado da Cancelamento
            Livewire.on('cancelConcluid', function(data) {
                Swal.fire({
                    title: 'Sucesso!',
                    text: `Pedido Cancelado com sucesso!`,
                    icon: 'success',
                    confirmButtonText: 'OK'
                });
            });
        });
    </script>
@endsection
