<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Permissões',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Usuários',
                'url' => route('admin.usuarios'),
            ],
            [
                'label' => 'Usuário x Permissão',
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
                    <header class="card-header-title">Cargos do Usuário: <strong>{{ $user->name }}</strong>
                        &nbsp;&nbsp;<a href="{{ route('usuario.available', $user->uuid) }}"
                            class="btn btn-icon waves-effect waves-light btn-primary" style="float:right;">Vincular novo
                            Cargo</a></header>
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
                                @foreach ($roles as $role)
                                    <tr>
                                        <td>
                                            {{ $role->name }}
                                        </td>
                                        <td style="width=10px;">
                                            <a href="javascript:void(0)"
                                                wire:click="$dispatch('triggerRole', {id: '{{ $role->id }}'})"
                                                class="btn btn-danger">DESVINCULAR</a>
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
    <link rel="stylesheet" type="text/css" href="/assets/css/vendors/datatables.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/10.14.0/sweetalert2.min.css"
        integrity="sha512-A374yR9LJTApGsMhH1Mn4e9yh0ngysmlMwt/uKPpudcFwLNDgN3E9S/ZeHcWTbyhb5bVHCtvqWey9DLXB4MmZg=="
        crossorigin="anonymous" />
@endpush

@push('scripts')
<!-- Datatable -->
<script src="/assets/js/datatable/datatables/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.addEventListener('livewire:init', function() {
            Livewire.on('triggerRole', id => {
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
                        console.log(id);
                        Livewire.dispatch('detachRoles', {
                            id: id.id
                        }); // Chama o método delete no Livewire
                    }
                });
            });
        });
    </script>
@endpush
