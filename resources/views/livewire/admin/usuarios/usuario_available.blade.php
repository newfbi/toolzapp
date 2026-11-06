<div class="container-fluid">
    @include('layouts.partials.page-title', [
        'title' => 'Cargos x Permissão',
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
                'label' => 'Usuário',
                'url' => route('usuario.permissions', $user->uuid),
            ],
            [
                'label' => 'Cargos x Permissão',
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
            <div class="mb-4 card">
                <div class="card-header">
                    <header class="card-header-title">Cargos disponíveis para o Usuário:
                        <strong>{{ $user->name }}</strong>
                    </header>
                </div>
                <div class="card-body">
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th width="70px">
                                    <div class="mb-3 custom-control custom-checkbox check-xs">
                                        <input type="checkbox" class="custom-control-input" id="permissions"
                                            wire:click="selectAll"
                                            {{ count($checkRoles) === $roles->count() ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="permissions"></label>
                                        <p style="float: right;">Todos</p>
                                    </div>
                                </th>
                                <th>Nome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <form method="POST" wire:submit="attachRolesUser">
                                @foreach ($roles as $role)
                                    <tr>
                                        <td>
                                            <div class="mb-3 custom-control custom-checkbox check-xs">
                                                <input type="checkbox" class="custom-control-input"
                                                    id="roles-{{ $role->id }}" wire:model="checkRoles"
                                                    value="{{ $role->id }}">
                                                <label class="custom-control-label"
                                                    for="roles-{{ $role->id }}"></label>
                                            </div>
                                        </td>
                                        <td>
                                            {{ $role->name }}
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
        <!-- Column -->
    </div>
    <!-- Row end -->
</div>
