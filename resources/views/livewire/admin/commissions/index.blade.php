<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Comissões dos Consultores',
        'breadcrumbItems' => [
            ['label' => 'Home', 'url' => route('admin')],
            ['label' => 'Comissões'],
        ],
    ])

    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-md-12">
            <div class="mb-4 card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <header class="mb-0 card-header-title">Lista de Comissões</header>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input type="text" class="form-control" placeholder="Consultor, cliente, tipo, serviço"
                                wire:model.debounce.300ms="filterSearch">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive custom-scrollbar" data-simplebar="init">
                        <table class="table table-hover table-nowrap mb-0 text-nowrap" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th>Consultor</th>
                                    <th>Parceiro</th>
                                    <th>Cliente</th>
                                    <th>Tipo</th>
                                    <th>Serviço</th>
                                    <th>Valor Serviço</th>
                                    <th>Comissão</th>
                                    <th>Status</th>
                                    <th>Atualizado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($commissions as $c)
                                    <tr>
                                        <td>{{ $c->user?->name }}</td>
                                        <td>{{ $c->sponsor?->name }}</td>
                                        <td>{{ $c->client?->name }}</td>
                                        <td>{{ $c->tipo }}</td>
                                        <td>{{ $c->servico }}</td>
                                        <td>{{ number_format($c->valor_servico, 2, ',', '.') }}</td>
                                        <td>{{ number_format($c->valor, 2, ',', '.') }}</td>
                                        <td>
                                            <span class="badge badge-{{ $c->status === 'pendente' ? 'warning' : ($c->status === 'pago' ? 'success' : 'secondary') }}">{{ $c->status }}</span>
                                        </td>
                                        <td>{{ date('d/m/Y H:i', strtotime($c->updated_at)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $commissions->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

