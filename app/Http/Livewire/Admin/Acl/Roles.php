<?php

namespace App\Http\Livewire\Admin\Acl;

use App\Models\Traits\Role;
use Livewire\Attributes\On;
use Livewire\Component;

class Roles extends Component
{
    public $name;
    public $description;
    public $roles;
    public $role_view_name;
    public $role_view_desc;
    public $role_id;

    public function mount()
    {
        view()->share('title', 'Regras');
        $this->roles = Role::all();
    }

    public function render()
    {
        return view('livewire.admin.acl.roles');
    }

    public function storeRole(){
        $this->dispatch('fecharModal');
        $this->validate();
        Role::create([
            'name' => $this->name,
            'description' => $this->description
        ]);
        $this->reset('name', 'description');
        session()->flash('message', 'Regra criada com sucesso!');
    }

    public function rules()
    {
        return [
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|min:8|max:255',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'O nome da regra é obrigatorio. Verifique e tente novamente.',
        ];
    }

    public function updateItemModal($id = null)
    {
        $role = Role::where('id', $id)->first();
        $this->role_view_name = $role->name;
        $this->role_view_desc = $role->description;
        $this->role_id = $role->id;
        $this->dispatch('show_up_modal');
    }

    public function updateItem($id = null)
    {
        $this->dispatch('fecharUpModal');
        $role = Role::where('id', $id)->first();
        $role->name = $this->role_view_name;
        $role->description = $this->role_view_desc;
        $role->save();
        $this->roles = Role::all();
        session()->flash('message', 'Regra Atualizada com sucesso.');
        return;
    }

    #[on('deleteRole')]
    public function excludeRole($id)
    {
        $role = Role::where('id', $id)->first();
        if (!$role) {
            session()->flash('error', 'Regra não encontrada.');
            return;
        }
        $role->delete();
        $this->roles = Role::all();
        session()->flash('message', 'Regra deletada com sucesso.');
        return;
    }
}
