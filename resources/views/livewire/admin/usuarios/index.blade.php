<!-- breadcrumb end -->
<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Gerenciar Usuários',
        'breadcrumbItems' => [
            [
                'label' => 'Home',
                'url' => route('admin'),
            ],
            [
                'label' => 'Usuários',
            ],
        ],
    ])
    <!--Row-->
    <div class="row row-sm">
        <div style="margin-top: 0.2rem">
            @include('layouts.includes.errors-and-messages')
        </div>
        <div class="col-12">
            <div class="card">
                <div class="d-flex align-items-center justify-content-between card-header">
                    <h4 class="mb-0 card-title">Painel de Usuários</h4>
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
                                    <th>Agentes</th>
                                    <th>Chats</th>
                                    <th>Conversões</th>
                                    <th>Plano</th>
                                    <th>Status</th>
                                    <th>Vencimento</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($users as $user)
                                    <tr>
                                        <td>{{ $user->name }}</td>
                                        <td>{{ $user->email }}</td>
                                        <td>{{ $user->phone }}</td>
                                        <td>{{ $user->agents->count() }}</td>
                                        <td>{{ $user->chats->count() }}</td>
                                        <td>{{ $user->convertedChats->count() }}</td>
                                        <td>{{ $user->plan ? Str::upper($user->plan->tipo) : 'INATIVO' }}</td>
                                        <td>
                                            @if ($user->status == 'active')
                                                <p class="text-success">Ativo</p>
                                            @elseif($user->status == 'disabled')
                                                <p class="text-danger">Inativo</p>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($user->payments->isNotEmpty())
                                                @php
                                                    $lastPayment = $user->payments->first(); // já está ordenado pelo updated_at desc
                                                @endphp
                                                {{ Date('d/m/Y', strTotime($lastPayment->validade)) }}
                                            @else
                                                <p class="text-muted">'--/--/--'</p>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="javascript:void(0)"
                                                class="btn btn-icon waves-effect waves-light btn-warning" title="Editar"
                                                wire:click="updateItemModal('{{ $user->uuid }}')">
                                                <i class="fa fa-wrench"></i> Editar
                                            </a>
                                            <a href="{{ route('usuario.permissions', $user->uuid) }}"
                                                class="btn btn-primary" title="Cargos"><i class="fa fa-book"></i> Cargos
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $users->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row end -->

    <!-- Add Order -->
    <div class="modal fade" id="editUser" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Usuário</h5>
                    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form method="post" wire:submit="updateUser">
                        <div class="row">
                            <div class="mt-2 col-md-6">
                                <label for="name">Nome Completo</label>
                                <input type="text" id="name" name="name" wire:model='name'
                                    class="form-control" placeholder="Nome Completo">
                            </div>
                            <div class="mt-2 col-md-6">
                                <label for="email">Email</label>
                                <input type="email" name="email" wire:model='email' class="form-control"
                                    placeholder="Email do Usuário">
                            </div>
                            <div class="mt-2 col-md-6">
                                <label for="phone">Telefone</label>
                                <input type="text" id="phone" name="phone" wire:model='phone'
                                    class="form-control" placeholder="Telefone">
                            </div>
                            <div class="mt-2 col-md-6">
                                <label for="password">Senha</label>
                                <input type="password" id="password" name="password" wire:model='password'
                                    class="form-control" placeholder="Digite a Senha">
                            </div>
                            <div class="mt-2 col-md-4">
                                <label class="col-md-12">Plano:</label>
                                <select class="form-control form-control-line" name="status" wire:model="plano"
                                    required>
                                    <option value="">Selecione</option>
                                    <option value="gold">Gold</option>
                                    <option value="silver">Silver</option>
                                    <option value="black">Black</option>
                                    <option value="platinum">Platinum</option>
                                </select>
                            </div>
                            <div class="mt-2 col-md-4">
                                <label class="col-md-12">Mensalidade:</label>
                                <select class="form-control form-control-line" name="status" wire:model="mensalidade"
                                    required>
                                    <option value="">Selecione</option>
                                    <option value="30">Mensal</option>
                                    <option value="60">Semestral</option>
                                    <option value="365">Anual</option>
                                </select>
                            </div>
                            <div class="mt-2 col-md-4">
                                <label class="col-md-12">Status:</label>
                                <select class="form-control form-control-line" name="status" wire:model="status"
                                    required>
                                    <option value="">Selecione</option>
                                    <option value="active">Ativo</option>
                                    <option value="disabled">Inativo</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group mt-3">
                            <button type="submit" wire:loading.attr="disabled"
                                class="btn btn-primary">Editar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
