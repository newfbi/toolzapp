<?php

namespace App\Http\Livewire\Admin\Usuarios;

use App\Models\User;
use Livewire\Component;

class UserAvailable extends Component
{
    public $user;
    public $roles;
    public $checkRoles = [];

    public function mount($id)
    {
        view()->share('title', 'Cargos Disponíveis');
        $this->user = User::where('uuid', $id)->first();
        $this->roles = $this->user->rolesAvailable();
    }

    public function render()
    {
        return view('livewire.admin.usuarios.usuario_available');
    }

    public function selectAll()
    {
        if (count($this->checkRoles) === $this->roles->count()) {
            $this->checkRoles = []; // Desmarcar tudo
            return;
        } else {
            $this->checkRoles = $this->roles->pluck('id')->toArray(); // Marcar tudo
            return;
        }
    }

    public function attachRolesUser()
    {
        if (!$this->checkRoles or count($this->checkRoles) == 0) {
            session()->flash('warning', 'Precisa escolher pelo menos uma regra');
            return;
        }
        if (count($this->checkRoles) > 1) {
            session()->flash('warning', 'Escolha apenas uma regra, caso tenha alguma já vinculada ao usuário, ela será removida!');
            return;
        }

        $this->user->roles()->detach();
        $this->user->roles()->attach($this->checkRoles);
        session()->flash('message', 'Permissões vinculadas com sucesso!');
        $this->roles = $this->user->rolesAvailable();
        $this->user->refresh();
        return;
    }
}
