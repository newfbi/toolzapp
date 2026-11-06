<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Regras',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Regras',
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
        <div class="col-md-12">
            <div class="mb-4 card card-box">
                <div class="card-body">
                    <div class="align-items-center justify-content-between mb-4 row">
                        <div class="mb-3 mb-lg-0 col-lg-3">
                            <h5>Detalhes das Regras</h5>
                        </div>
                        <div class="d-flex justify-content-lg-end col-lg-6">
                            <a href="javascript:void(0)" class="btn btn-primary btn-add" data-bs-toggle="modal"
                                data-bs-target="#addNewPermission">
                                <i class="me-1 ri-add-line"></i>
                                Criar Regra
                            </a>
                        </div>
                    </div>
                    <div class="table-box table-responsive">
                        <table class="table table-hover text-nowrap" id="table_1" width="100%">
                            <thead>
                                <tr>
                                    <th width="20%">Nome</th>
                                    <th>Descrição</th>
                                    <th width="20%">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roles as $role)
                                    <tr>
                                        <td>
                                            {{ $role->name }}
                                        </td>
                                        <td>
                                            {{ $role->description }}
                                        </td>
                                        <td>
                                            <a href="{{ route('roles.permissions', $role->id) }}"
                                                class="btn icon-btn-sm btn-light-info" title="Cargos">
                                                <i class="bi bi-keyboard"></i>
                                            </a>
                                            <a href="javascript:void(0)" class="btn icon-btn-sm btn-light-warning"
                                                title="Editar" wire:click="updateItemModal('{{ $role->id }}')">
                                                <i class="bi bi-wrench"></i>
                                            </a>
                                            <a href="javascript:void(0)" class="btn icon-btn-sm btn-light-danger"
                                                title="Deletar"
                                                wire:click="$dispatch('triggerDelete', {id: '{{ $role->id }}'})">
                                                <i class="bi bi-trash"></i>
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
    <!-- Modal -->
    <div class="modal fade" id="addNewPermission" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar nova Regra</h5>
                    <button class="py-0 btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" wire:submit="storeRole">
                        <div class="row">
                            <div class="mt-2 col-md-6">
                                <label for="description">Nome da Regra</label>
                                <input type="text" id="name" name="name" wire:model='name'
                                    class="form-control" placeholder="Digite o Nome da Regra">
                            </div>
                            <div class="mt-2 col-md-6">
                                <label for="description">Descrição da Regra</label>
                                <textarea class="form-control" name="description" id="description" wire:model="description" cols="30"
                                    rows="10"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <button type="submit" wire:loading.attr="disabled"
                                class="btn btn-primary">Cadastrar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- End Modal -->
    <!-- Modal -->
    <div class="modal fade" id="updatePermission" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Regra</h5>
                    <button class="py-0 btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" wire:submit="updateItem('{{ $role_id }}')">
                        <div class="row">
                            <div class="mt-2 col-md-6">
                                <label for="description">Nome da Regra</label>
                                <input type="text" id="role_view_name" name="role_view_name"
                                    wire:model='role_view_name' class="form-control"
                                    placeholder="Digite o Nome da Regra">
                            </div>
                            <div class="mt-2 col-md-6">
                                <label for="description">Descrição da Regra</label>
                                <textarea class="form-control" name="role_view_desc" id="role_view_desc" wire:model="role_view_desc" cols="30"
                                    rows="10"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <button type="submit" wire:loading.attr="disabled"
                                class="btn btn-primary">Atualizar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- End Modal -->
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
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets/js/datatable/datatables/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.addEventListener('livewire:init', function() {
            Livewire.on('fecharModal', function() {
                $('#addNewPermission').modal('hide');
            });

            Livewire.on('show_up_modal', function(data) {
                $('#updatePermission').modal('show');
            });

            Livewire.on('fecharUpModal', function() {
                $('#updatePermission').modal('hide');
            });

            Livewire.on('triggerDelete', id => {
                Swal.fire({
                    title: 'Você tem certeza?',
                    text: 'Esta ação não poderá ser desfeita!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Sim, deletar!',
                    cancelButtonText: 'Cancelar',
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch('deleteRole', {
                            id: id.id
                        }); // Chama o método delete no Livewire
                    }
                });
            });
        });
    </script>
    <script>
        function initAllDataTables() {
            document.querySelectorAll('table[id^="table_"]').forEach(function(table) {
                // Destroy se já estiver inicializado
                if ($.fn.DataTable.isDataTable(table)) {
                    $(table).DataTable().destroy();
                }

                // Inicializa com exportação
                $(table).DataTable({
                    dom: 'Bfrtip',
                    language: {
                        lengthMenu: "Quantidade _MENU_ por página",
                        zeroRecords: "Nenhum item encontrado",
                        info: "Mostrando _PAGE_ de _PAGES_",
                        infoEmpty: "Nenhuma Página",
                        infoFiltered: "(Encontrados _MAX_ total pesquisa)",
                        search: "Pesquisa:",
                        loadingRecords: "Carregando...",
                        paginate: {
                            next: ">>>",
                            previous: "<<<"
                        }
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', initAllDataTables);

        document.addEventListener('livewire:init', function() {
            Livewire.hook('request', () => {
                setTimeout(() => {
                    initAllDataTables();
                }, 300);
            });
        });
    </script>
@endpush
