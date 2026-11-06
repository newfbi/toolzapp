<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Saques',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Saques']],
    ])
    <div class="row">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-sm-6">
            <div class="card">
                <div class="card-body">
                    <section class="panel">
                        <div class="panel-heading">
                            <h4>Efetuar Saque</h4>
                            <hr>
                        </div>
                        <div class="panel-body">
                            <form method="POST" wire:submit.prevent="storeWithdraw">
                                @csrf
                                <input type="hidden" name="_method" value="post">
                                <div class="form-group">
                                    <label for="TextBoxNome">Chave Pix</label>
                                    <input name="chave_pix" type="text" wire:model="chave_pix" class="form-control"
                                        placeholder="Confirme sua chave PIX" required="">
                                </div>
                                <div class="form-group mt-1">
                                    <label for="TextBoxNome">Valor do Saque</label>
                                    <input name="valor_saque" type="text" wire:model="valor_saque"
                                        class="form-control" placeholder="Valor maximo é R${{ $user->saldo }}"
                                        required="">
                                </div>
                                <button type="submit" class="mt-3 btn btn-primary">Confirmar Saque</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="overflow-hidden card">
                <div class="p-5 text-center overlay-box" style="padding-bottom: 0px !important;">
                    <h3 class="mt-3 mb-0 text-primary">{{ $user->name }}</h3>
                    <div class="mt-3 alert alert-light-warning" role="alert">
                        <span>As retiradas podem ser aprovadas no prazo de 48 horas a contar da data da
                            retirada</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center row">
                        <div class="col-6">
                            <div class="p-3 rounded bgl-primary">
                                <h5 class="mb-0">Saldo Disponivel</h5>
                                <small>R${{ App\Helpers\Utils::MoedaBr($user->saldo) ?? '0,00' }}</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 rounded bgl-primary">
                                <h5 class="mb-0">Total de Retiradas</h5>
                                <small>R${{ App\Helpers\Utils::MoedaBr($totalRetiradas) ?? '0,00' }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="mb-4 card">
                <div class="card-header">
                    <header class="card-header-title">Painel de Gerenciamento de Saques</header>
                </div>
                <div class="card-body">
                    <div class="table-box table-responsive">
                        <table class="table table-hover text-nowrap">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Chave Pix</th>
                                    <th scope="col">Valor</th>
                                    <th scope="col">tipo</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Data Retirada</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($retiradas as $Retirada)
                                    <tr>
                                        <td style="text-transform:uppercase;">{{ $Retirada->chave_pix }}</td>
                                        <td>
                                            <span class="text-warning">
                                                {{ App\Helpers\Utils::MoedaBr($Retirada->valor) ?? '0,00' }}
                                            </span>
                                        </td>
                                        <td>{{ $Retirada->tipo }}</td>
                                        <td scope="row">
                                            @if ($Retirada->status == 'aberto')
                                                <p class="text-primary">Aguardando</p>
                                            @elseif($Retirada->status == 'pendente')
                                                <p class="text-warning">Pendente</p>
                                            @elseif($Retirada->status == 'creditado')
                                                <p class="text-success">Ativo</p>
                                            @elseif($Retirada->status == 'cancelado')
                                                <p class="text-danger">Cancelado</p>
                                            @endif
                                        </td>
                                        <td scope="row">{{ date('d/m/Y', strtotime($Retirada->data)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@section('css')
    <!-- Data Table css-->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendor/datatable/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" type="text/css"
        href="{{ asset('assets/vendor/datatable/datatable2/buttons.dataTables.min.css') }}">
@endsection

@section('scripts')
    <!-- datatable js -->
    <script src="{{ asset('assets/vendor/datatable/jquery.dataTables.min.js') }}"></script>
    <!-- js-->
    <script src="{{ asset('/assets/js/data_table.js') }}"></script>
    <script>
        document.addEventListener('livewire:init', function() {
            // Escuta quando o Livewire re-renderiza a tabela e reinicia o DataTable
            Livewire.hook('request', (event) => {
                setTimeout(() => {
                    initDataTable();
                }, 300); // Pequeno delay para garantir que a tabela foi carregada no DOM
            });
        });
    </script>
@endsection
