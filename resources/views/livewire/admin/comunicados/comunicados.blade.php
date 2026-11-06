<div class="container-fluid">
    <div class="page-titles">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin') }}">Admin</a></li>
            <li class="breadcrumb-item active"><a href="javascript:void(0)">Gerenciar Avisos</a></li>
        </ol>
    </div>
    <!-- row -->
    <div class="row">
        <div class="col-xl-12 col-xxl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Quadro de Aviso</h4>
                </div>
                <div class="card-body">
                    <form wire:submit.prevent="store">
                        <div class="form-group">
                            <label for="title">Titulo</label>
                            <input type="text" class="form-control" wire:model="title">
                        </div>
                        <div class="form-group" wire:ignore>
                            <label for="message">Mensagem</label>
                            <div id="summernote" class="summernote"></div>
                        </div>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-header">
                    <header class="card-header-title">Detalhes das Listas</header>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="display min-w850" id="example5" width="100%">
                            <thead>
                                <tr>
                                    <th>Titulo</th>
                                    <th>Mensagem</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($aviso)
                                    <tr>
                                        <td>
                                            {{ $aviso->title }}
                                        </td>
                                        <td>
                                            {!! $aviso->message !!}
                                        </td>
                                        <td>
                                            @if ($aviso->status == 'active')
                                                <span class="badge badge-success">Ativo</span>
                                            @elseif ($aviso->status == 'disabled')
                                                <span class="badge badge-warning">Desabilitado</span>
                                            @elseif ($aviso->status == 'cancel')
                                                <span class="badge badge-danger">Cancelado</span>
                                            @endif
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-info light sharp mb-2"
                                                data-toggle="modal"
                                                data-target="#exampleModalCenter"title="Viusualizar">
                                                <i class="fa fa-eye"></i>
                                            </button>
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-warning light sharp"
                                                    data-toggle="dropdown" aria-expanded="false">
                                                    <svg width="20px" height="20px" viewBox="0 0 24 24"
                                                        version="1.1">
                                                        <g stroke="none" stroke-width="1" fill="none"
                                                            fill-rule="evenodd">
                                                            <rect x="0" y="0" width="24" height="24"></rect>
                                                            <circle fill="#000000" cx="5" cy="12" r="2">
                                                            </circle>
                                                            <circle fill="#000000" cx="12" cy="12" r="2">
                                                            </circle>
                                                            <circle fill="#000000" cx="19" cy="12" r="2">
                                                            </circle>
                                                        </g>
                                                    </svg>
                                                </button>
                                                <div class="dropdown-menu" x-placement="top-start"
                                                    style="position: absolute; will-change: transform; top: 0px; left: 0px; transform: translate3d(0px, 0px, 0px);">
                                                    @if ($aviso->status == 'active')
                                                        <a class="dropdown-item"
                                                            wire:click="updateStatus('disabled')">Desabilitar</a>
                                                        <a class="dropdown-item"
                                                            wire:click="updateStatus('cancel')">Cancelar</a>
                                                    @elseif ($aviso->status == 'disabled')
                                                        <a class="dropdown-item"
                                                            wire:click="updateStatus('active')">Reativar</a>
                                                        <a class="dropdown-item"
                                                            wire:click="updateStatus('cancel')">Cancelar</a>
                                                    @elseif ($aviso->status == 'cancel')
                                                        <a class="dropdown-item"
                                                            wire:click="updateStatus('active')">Reativar</a>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade bd-example-modal-lg" id="exampleModalCenter" tabindex="-1" role="dialog"
        style="display: none;" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: black;">
                <div class="modal-header">
                    <h3 class="modal-title text-warning">Aviso Soft Crédito</h3>
                    <button type="button" class="close" data-dismiss="modal"><span>×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <h1 class="text-white">{{$aviso->title}}</h1>
                    <div style="color: white;">
                        {!! $aviso->message !!}
                    </div>
                </div>
                <div class="modal-footer">
                    <p class="text-white">Atenciosamente,</p>
                    <p class="text-white"><b>Equipe Soft Crédito</b></p>
                    <button type="button" class="btn btn-primary" data-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
    <!-- Summernote -->
    <link href="/assets_d/vendor/summernote/summernote.css" rel="stylesheet">
@endpush

@push('scripts')
    <!-- Summernote -->
    <script src="/assets_d/vendor/summernote/js/summernote.min.js"></script>
    <!-- Summernote init -->
    <script src="/assets_d/js/plugins-init/summernote-init.js"></script>
    <!-- Datatable -->
    <script src="/assets_d/vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="/assets_d/js/plugins-init/datatables.init.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            $('#summernote').summernote({
                height: 200, // Altura do editor
                placeholder: 'Digite sua mensagem...',
                callbacks: {
                    onChange: function(contents, $editable) {
                        @this.set('message', contents); // Envia para o Livewire
                    }
                }
            });

            // Para manter os dados ao recarregar (exemplo ao editar)
            Livewire.hook('message.processed', (message, component) => {
                $('#summernote').summernote('code', @this.get('message'));
            });
        });
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
