<?php

namespace App\Http\Livewire\Admin\Acl;

use App\Models\Traits\Permission;
use App\Models\Traits\Role;
use Livewire\Attributes\On;
use Livewire\Component;

class PermissionRoles extends Component
{
    public $permission;

    public function mount($id){
        view()->share('title', 'Permissões x Regras');
        $this->permission = Permission::find($id);
        if(!$this->permission){
            return redirect()->route('permissions')->with('error', 'Permissão não encontrada!');
        }
    }

    public function render()
    {
        return view('livewire.admin.acl.permission_roles');
    }

    #[on('detachRole')]
    public function detachPermissionRole($idRole)
    {
        $role = Role::find($idRole);
        $permission = $this->permission->id;
        $role->permissions()->detach($permission);
        session()->flash('message', 'Regra removida com sucesso!');
        return;
    }
}
