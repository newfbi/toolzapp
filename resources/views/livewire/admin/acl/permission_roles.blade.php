<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Permissão x Regras',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Permissões',
                'url' => route('permissions'),
            ],
            [
                'label' => 'Permissão x Regras',
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
            <div class="mb-4 card card-box">
                <div class="card-body">
                    <div class="align-items-center justify-content-between mb-4 row">
                        <div class="mb-3 mb-lg-0 col-lg-12">
                            <h5>Perfis da permissão: {{ $permission->name }}</h5>
                        </div>
                    </div>
                    <div class="table-box table-responsive">
                        <table class="table table-hover text-nowrap" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th width="20%">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($permission->roles as $role)
                                    <tr>
                                        <td>
                                            {{ $role->name }}
                                        </td>
                                        <td>
                                            <a href="javascript:void(0)" class="btn icon-btn-sm btn-light-danger"
                                                title="Desvincular"
                                                wire:click="$dispatch('triggerRole', {id: '{{ $role->id }}'})">
                                                <i class="bi bi-x-circle"></i>
                                            </a>
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
                        Livewire.dispatch('detachRole', {
                            idRole: id.id
                        }); // Chama o método delete no Livewire
                    }
                });
            });
        });
    </script>
@endpush
