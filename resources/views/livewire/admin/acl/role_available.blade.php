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
                'label' => 'Regra',
                'url' => route('roles.permissions', $role->id),
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
                    <header class="card-header-title">Permissões disponíveis ao cargo:
                        <strong>{{ $role->name }}</strong>
                    </header>
                </div>
                <div class="card-body">
                    <div class="table-responsive custom-scrollbar">
                        <table class="display" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th width="70px">
                                        <div class="custom-control custom-checkbox mb-3 check-xs">
                                            <p style="float: right;">Todos</p>
                                            <input type="checkbox" class="custom-control-input" id="permissions"
                                                wire:click="selectAll"
                                                {{ count($checkPermissions) === $permissions->count() ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="permissions"></label>
                                        </div>
                                    </th>
                                    <th>Nome</th>
                                </tr>
                            </thead>
                            <tbody>
                                <form method="POST" wire:submit="attachPermissionsRole">
                                    @foreach ($permissions as $permission)
                                        <tr>
                                            <td>
                                                <div class="custom-control custom-checkbox mb-3 check-xs">
                                                    <input type="checkbox" class="custom-control-input"
                                                        id="permission-{{ $permission->id }}"
                                                        wire:model="checkPermissions" value="{{ $permission->id }}">
                                                    <label class="custom-control-label"
                                                        for="permission-{{ $permission->id }}"></label>
                                                </div>
                                            </td>
                                            <td>
                                                {{ $permission->name }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td colspan="500">
                                            <button type="submit" class="btn btn-success">Vincular</button>
                                        </td>
                                    </tr>
                                </form>
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
                        Livewire.dispatch('detachRole', {
                            idRole: id.id
                        }); // Chama o método delete no Livewire
                    }
                });
            });
        });
    </script>
@endpush
