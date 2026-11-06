<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Extratos',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Extratos']],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-sm-6 col-lg-6 col-xl-3">
            <div class="card widget-stat">
                <div class="p-4 card-body">
                    <div class="ai-icon media">
                        <div class="media-body">
                            <p class="mb-1">Disponivel</p>
                            <h4 class="mb-0">R$ {{ App\Helpers\Utils::MoedaBr($user->saldo) ?? '0,00' }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-6 col-xl-3">
            <div class="card widget-stat">
                <div class="p-4 card-body">
                    <div class="ai-icon media">
                        <div class="media-body">
                            <p class="mb-1">Total Comissão</p>
                            <h4 class="mb-0">R$ {{ App\Helpers\Utils::MoedaBr($comissao) ?? '0,00' }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-6 col-xl-3">
            <div class="card widget-stat">
                <div class="p-4 card-body">
                    <div class="ai-icon media">
                        <div class="media-body">
                            <p class="mb-1">Total do Mês</p>
                            <h4 class="mb-0">R$ {{ App\Helpers\Utils::MoedaBr($totalMonth) ?? '0,00' }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-6 col-xl-3">
            <div class="card widget-stat">
                <div class="p-4 card-body">
                    <div class="ai-icon media">
                        <div class="media-body">
                            <p class="mb-1">Indicados Ativos</p>
                            <h4 class="mb-0">{{ $clientes }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 w-100">
                        <h5 class="mb-0 card-title">Gerenciamento de Extratos</h5>
                        <div class="input-group" style="max-width: 320px;">
                            <span class="input-group-text">
                                <i class="fa fa-search"></i>
                            </span>
                            <input type="text" class="form-control" wire:model.live="search"
                                placeholder="Buscar por consultor, serviço, status..." autocomplete="off">
                            @if ($search)
                                <button type="button" class="btn-outline-secondary btn"
                                    wire:click="$set('search', '')">
                                    <i class="fa fa-times"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                    @if ($search)
                        <div class="mt-2 text-muted text-end small">
                            {{ $pagamentos->count() }} resultado(s) de {{ $totalCount }} para "{{ $search }}"
                        </div>
                    @endif
                </div>
                <div class="card-body">
                    <div class="table-box table-responsive">
                        <table class="table table-hover text-nowrap">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width: 40px;"></th>
                                    <th scope="col">Patrocinador</th>
                                    <th scope="col">Consultor</th>
                                    <th scope="col">Serviço</th>
                                    <th scope="col">Valor Cobrado</th>
                                    <th scope="col">Valor Comissão</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Data Cadastro</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pagamentos as $pagamento)
                                    @php
                                        $isExpanded = $expandedCommission == $pagamento->id;
                                    @endphp
                                    <tr>
                                        <td>
                                            @if ($pagamento->payment_id)
                                                <button type="button" class="p-0 btn btn-sm btn-link"
                                                    wire:click="toggleCommissionNetwork({{ $pagamento->id }})"
                                                    title="Ver distribuição em rede">
                                                    <i
                                                        class="fas fa-{{ $isExpanded ? 'arrow-up' : 'arrow-down' }} fs-5"></i>
                                                </button>
                                            @endif
                                        </td>
                                        <td style="text-transform:uppercase;">{{ $user->name ?? 'N/A' }}
                                        </td>
                                        <td style="text-transform:uppercase;">{{ $pagamento->client->name ?? 'N/A' }}
                                        </td>
                                        <td>{{ $pagamento->servico }}</td>
                                        <td>
                                            <span class="text-warning">
                                                {{ App\Helpers\Utils::MoedaBr($pagamento->valor_servico) ?? '0,00' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-success">
                                                {{ App\Helpers\Utils::MoedaBr($pagamento->valor) ?? '0,00' }}
                                            </span>
                                        </td>
                                        <td scope="row">
                                            @if ($pagamento->status == 'aberto')
                                                <p class="text-primary">Aguardando</p>
                                            @elseif($pagamento->status == 'pendente')
                                                <p class="text-warning">Pendente</p>
                                            @elseif($pagamento->status == 'enabled')
                                                <p class="text-success">Ativo</p>
                                            @elseif($pagamento->status == 'disabled')
                                                <p class="text-danger">Cancelado</p>
                                            @endif
                                        </td>
                                        <td scope="row">{{ date('d/m/Y', strtotime($pagamento->created_at)) }}</td>
                                    </tr>
                                    @if ($isExpanded)
                                        @php
                                            $network = $this->getCommissionNetwork($pagamento->id);
                                        @endphp
                                        @if (!empty($network))
                                            <tr>
                                                <td colspan="9" class="p-0">
                                                    <div class="bg-light p-3 accordion-body">
                                                        <div class="table-box table-responsive">
                                                            <table class="table table-hover text-nowrap">
                                                                <tbody>
                                                                    @foreach ($network as $item)
                                                                        <tr>
                                                                            <td style="text-transform:uppercase;">
                                                                                {{ $item['user_name'] }}</td>
                                                                            <td style="text-transform:uppercase;">
                                                                                {{ $item['client_name'] }}</td>
                                                                            <td>{{ $item['servico'] }}</td>
                                                                            <td class="text-center">
                                                                                @if ($item['clientes_count'] > 0)
                                                                                    {{ $item['clientes_count'] }}
                                                                                @else
                                                                                    0
                                                                                @endif
                                                                            </td>
                                                                            <td>
                                                                                <span class="text-warning">
                                                                                    {{ App\Helpers\Utils::MoedaBr($item['valor_servico']) ?? '0,00' }}
                                                                                </span>
                                                                            </td>
                                                                            <td>
                                                                                <span class="text-success">
                                                                                    {{ App\Helpers\Utils::MoedaBr($item['valor']) ?? '0,00' }}
                                                                                </span>
                                                                            </td>
                                                                            <td scope="row">
                                                                                @if ($item['status'] == 'aberto')
                                                                                    <p class="text-primary">Aguardando
                                                                                    </p>
                                                                                @elseif($item['status'] == 'pendente')
                                                                                    <p class="text-warning">Pendente</p>
                                                                                @elseif($item['status'] == 'enabled')
                                                                                    <p class="text-success">Ativo</p>
                                                                                @elseif($item['status'] == 'disabled')
                                                                                    <p class="text-danger">Cancelado</p>
                                                                                @endif
                                                                            </td>
                                                                            <td scope="row">
                                                                                {{ date('d/m/Y', strtotime($item['created_at'])) }}
                                                                            </td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-center mt-3">
                        @if ($hasMorePages)
                            <button class="btn-outline-primary btn" wire:click="loadMore">
                                <i class="me-1 fa fa-plus"></i> Carregar mais
                            </button>
                        @else
                            <div class="text-muted">
                                <i class="me-1 fa fa-check"></i> Todos os registros foram carregados
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
