<?php

namespace App\Http\Livewire\Admin\Usuarios;

use App\Models\Traits\Role;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\On;

class UserPermissions extends Component
{
    public $user, $roles;

    public function mount($id)
    {
        view()->share('title', 'Cargos do Usuário');
        $this->user = User::where('uuid', $id)->first();
        $this->roles = $this->user->roles;
    }

    public function render()
    {
        return view('livewire.admin.usuarios.user_permissions');
    }

    #[on('detachRoles')]
    public function detachUserRole($id)
    {
        $role = Role::find($id);
        $this->user->roles()->detach($role);
        $this->user->refresh();
        $this->roles = $this->user->roles;
        session()->flash('message', 'Permissão removida com sucesso!');
        return;
    }
}
