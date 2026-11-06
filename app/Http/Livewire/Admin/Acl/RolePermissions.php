<?php

namespace App\Http\Livewire\Admin\Acl;

use App\Models\Traits\Permission;
use App\Models\Traits\Role;
use Livewire\Attributes\On;
use Livewire\Component;

class RolePermissions extends Component
{
    public $role;

    public function mount($id) {
        view()->share('title', 'Regras x Permissões');
        $this->role = Role::find($id);
        if(!$this->role){
            return redirect()->route('permissions')->with('error', 'Regra não encontrada!');
        }
    }

    public function render()
    {
        return view('livewire.admin.acl.role_permissions');
    }

    #[on('detachPermission')]
    public function detachPermissionPermission($id)
    {
        $permission = Permission::find($id);
        $role = $this->role->id;
        $permission->roles()->detach($role);
        session()->flash('message', 'Permissão removida com sucesso!');
        return;
    }
}
