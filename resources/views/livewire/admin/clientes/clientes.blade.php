<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Lista de Clientes',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Clientes',
            ],
        ],
    ])
    <!-- row -->
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-md-12">
            <div class="mb-4 card">
                <div class="card-header">
                    <header class="card-header-title">Detalhes dos Clientes</header>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="display min-w850" id="example5" width="100%">
                            <thead>
                                <tr>
                                    <th>Franqueado</th>
                                    <th>Consultor</th>
                                    <th>Nome</th>
                                    <th>Serviço</th>
                                    <th>Valor Processo</th>
                                    <th>PDF Consulta</th>
                                    <th>Contrato Processo</th>
                                    <th>Contrato Associativo</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($clientes as $cliente)
                                    <tr>
                                        <td>
                                            {{ $cliente->sponsor->sponsor->name }}
                                        </td>
                                        <td>
                                            {{ $cliente->sponsor->name }}
                                        </td>
                                        <td>
                                            {{ $cliente->name }}
                                        </td>
                                        <td>
                                            {{ $cliente->tipo_servico }}
                                        </td>
                                        <td>
                                            R$ {{ number_format($cliente->valor_servico, 2, ',', '.') }}
                                        </td>
                                        <td>
                                            @php
                                                $pdf_servico = $cliente
                                                    ->documents()
                                                    ->where('documento', 'contrato')
                                                    ->value('uuid');
                                                $pdf_associativo = $cliente
                                                    ->documents()
                                                    ->where('documento', 'associativo')
                                                    ->value('uuid');
                                            @endphp
                                            <a href="{{ asset('storage/img/clients/pdf/' . $cliente->uuid . '_consulta.pdf') }}"
                                                class="btn btn-primary btn-sm">PDF</a>
                                        </td>
                                        <td>
                                            <a href="javascript:void(0)" wire:click="getContrato('{{ $pdf_servico }}')"
                                                class="btn btn-primary btn-sm">Contrato
                                                Processo</a>
                                        </td>
                                        <td>
                                            <a href="javascript:void(0)"
                                                wire:click="getContrato('{{ $pdf_associativo }}')"
                                                class="btn btn-primary btn-sm">Contrato
                                                Associativo</a>
                                        </td>
                                        <td>
                                            @if ($cliente->status == 'pendente')
                                                <span class="badge badge-warning">Pendente</span>
                                            @elseif ($cliente->status == 'ativo')
                                                <span class="badge badge-success">Ativo</span>
                                            @elseif ($cliente->status == 'reprovado')
                                                <span class="badge badge-danger">Reprovado</span>
                                            @else
                                                <span class="badge badge-danger">Cancelado</span>
                                            @endif
                                        </td>
                                        <td>{{ Carbon\Carbon::parse($cliente->updated_at)->format('d/m/Y') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- row -->
</div>

@push('styles')
    <!-- Datatable -->
    <link href="/assets_d/vendor/datatables/css/jquery.dataTables.min.css" rel="stylesheet">
@endpush

@push('scripts')
    <!-- Datatable -->
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets_d/vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="/assets_d/js/plugins-init/datatables.init.js"></script>
    <script>
        document.addEventListener('livewire:init', function() {
            // Escuta quando o Livewire re-renderiza a tabela e reinicia o DataTable
            Livewire.hook('request', () => {
                setTimeout(() => {
                    initDataTable();
                }, 300); // Pequeno delay para garantir que a tabela foi carregada no DOM
            });
        });
    </script>
@endpush
