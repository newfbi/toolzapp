<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Regras x Permissão',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Regras',
                'url' => route('roles'),
            ],
            [
                'label' => 'Regras x Permissão',
            ],
        ],
    ])
    <!--Row-->
    <div class="row row-sm">
        <div class="col-md-12">
            <div style="margin-top: 0.2rem">
                @include('layouts.includes.errors-and-messages')
            </div>
        </div>
        <!-- Column -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <header class="card-header-title">Perfis da Regra: {{ $role->name }} &nbsp;&nbsp;<a
                            href="{{ route('roles.available', $role->id) }}" class="btn btn-icon btn-primary btn-xs"
                            style="float: right;">Vincular
                            nova Permissão</a></header>
                </div>
                <div class="card-body">
                    <div class="table-responsive custom-scrollbar">
                        <table class="display" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($role->permissions as $permission)
                                    <tr>
                                        <td>
                                            <strong>{{ $permission->name }}</strong>
                                        </td>
                                        <td style="width=10px;">
                                            <a href="javascript:void(0)"
                                                wire:click="$dispatch('triggerPermission', {id: '{{ $permission->id }}'})"
                                                class="btn btn-danger btn-sm">DESVINCULAR</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- Column -->
    </div>
    <!-- Row end -->
</div>

@push('styles')
    <!-- Datatable -->
    <link href="/assets_d/vendor/datatables/css/jquery.dataTables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/10.14.0/sweetalert2.min.css"
        integrity="sha512-A374yR9LJTApGsMhH1Mn4e9yh0ngysmlMwt/uKPpudcFwLNDgN3E9S/ZeHcWTbyhb5bVHCtvqWey9DLXB4MmZg=="
        crossorigin="anonymous" />
@endpush

@push('scripts')
    <!-- Datatable -->
    <script src="/assets_d/vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="/assets_d/js/plugins-init/datatables.init.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.addEventListener('livewire:init', function() {
            Livewire.on('triggerPermission', id => {
                Swal.fire({
                    title: 'Você tem certeza?',
                    text: 'Esta ação não poderá ser desfeita!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Sim, desvincular!',
                    cancelButtonText: 'Cancelar',
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch('detachPermission', {
                            id: id.id
                        }); // Chama o método delete no Livewire
                    }
                });
            });
        });
    </script>
@endpush
