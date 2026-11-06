<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Sistema de Indicação',
        'breadcrumbItems' => [['label' => 'Home', 'url' => route('dashboard')], ['label' => 'Indicações']],
    ])

    <div class="row row-sm">
        <div class="col-sm-3">
            <input type="text" id="copy" class="form-control solid"
                value="{{ route('afiliate.system', auth()->user()->uuid) }}" readonly>
        </div>
        <div class="d-flex mb-3 col-sm-7">
            <a class="me-2 btn btn-primary" onclick="click_copyCode()">Indicar Parceiro</a>
        </div>
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-12">
            <div class="card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <h4 class="mb-0 card-title">Lista de Indicações</h4>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="ri-search-line"></i></span>
                            <input type="text" class="form-control" placeholder="Nome, email ou telefone"
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
                                    <th>E-mail</th>
                                    <th>Telefone</th>
                                    <th>Parceiros</th>
                                    <th>Plano</th>
                                    <th>Status</th>
                                    <th>Cadastrado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($partners as $p)
                                    <tr>
                                        <td>{{ $p->name }}</td>
                                        <td>{{ $p->email }}</td>
                                        <td>{{ $p->phone }}</td>
                                        <td>{{ $p->children->count() }}</td>
                                        <td>{{ $p->plan ? Str::upper($p->plan->tipo) : 'INATIVO' }}</td>
                                        <td>
                                            @if ($p->status == 'active')
                                                <span class="text-success">Ativo</span>
                                            @elseif($p->status == 'disabled')
                                                <span class="text-danger">Inativo</span>
                                            @else
                                                <span class="text-muted">{{ $p->status }}</span>
                                            @endif
                                        </td>
                                        <td>{{ date('d/m/Y', strtotime($p->created_at)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $partners->links() }}
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
        $(document).ready(function() {
            let Toast = Swal.mixin({
                toast: true,
                position: 'bottom-end',
                showConfirmButton: false,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });

            window.click_copyCode = function() {
                var copyText = document.getElementById("copy");
                /* Prevent iOS keyboard from opening */
                copyText.readOnly = true;
                /* Change the input's type to text so its text becomes selectable */
                copyText.type = 'text';
                copyText.select();
                document.execCommand("copy");
                Toast.fire({
                    title: 'Link de Referencia, copiado com sucesso!',
                    icon: 'success',
                    timer: 2000
                });
            }
        })
    </script>
@endsection
