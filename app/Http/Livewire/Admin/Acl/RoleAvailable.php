<?php

namespace App\Http\Livewire\Admin\Acl;

use App\Models\Traits\Role;
use Livewire\Component;

class RoleAvailable extends Component
{
    public $role;
    public $permissions;
    public $checkPermissions = [];

    public function mount($id)
    {
        view()->share('title', 'Vincular Permissões');
        $this->role = Role::find($id);
        if (!$this->role) {
            return redirect()->route('roles')->with('warning', 'Regra não encontrada!');
        }
        $this->permissions = $this->role->permissionsAvailable();
    }

    public function render()
    {
        return view('livewire.admin.acl.role_available');
    }

    public function attachPermissionsRole()
    {

        if (!$this->checkPermissions or count($this->checkPermissions) == 0) {
            session()->flash('warning', 'Precisa escolher pelo menos uma permissão');
            return;
        }

        $this->role->permissions()->attach($this->checkPermissions);
        session()->flash('message', 'Permissões vinculadas com sucesso!');
        $this->permissions = $this->role->permissionsAvailable();
        return;
    }

    public function selectAll()
    {
        if (count($this->checkPermissions) === $this->permissions->count()) {
            $this->checkPermissions = []; // Desmarcar tudo
            return;
        } else {
            $this->checkPermissions = $this->permissions->pluck('id')->toArray(); // Marcar tudo
            return;
        }
    }
}
