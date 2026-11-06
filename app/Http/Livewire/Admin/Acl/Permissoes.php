<?php

namespace App\Http\Livewire\Admin\Acl;

use App\Models\Traits\Permission;
use Livewire\Attributes\On;
use Livewire\Component;

class Permissoes extends Component
{
    public $name;
    public $description;
    public $permissions;
    public $permission_view_name;
    public $permission_view_desc;
    public $permission_id;

    public function mount()
    {
        view()->share('title', 'Permissões');
        $this->permissions = Permission::all();
    }

    public function render()
    {
        return view('livewire.admin.acl.permissoes');
    }

    public function storePermission(){
        $this->dispatch('fecharModal');
        $this->validate();
        Permission::create([
            'name' => $this->name,
            'description' => $this->description
        ]);
        $this->reset('name', 'description');
        session()->flash('message', 'Permissão criada com sucesso!');
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
            'name.required' => 'O nome da permissão é obrigatorio. Verifique e tente novamente.',
        ];
    }

    public function updateItemModal($id = null)
    {
        $permission = Permission::where('id', $id)->first();
        $this->permission_view_name = $permission->name;
        $this->permission_view_desc = $permission->description;
        $this->permission_id = $permission->id;
        $this->dispatch('show_up_modal');
    }

    public function updateItem($id = null)
    {
        $this->dispatch('fecharUpModal');
        $permission = Permission::where('id', $id)->first();
        $permission->name = $this->permission_view_name;
        $permission->description = $this->permission_view_desc;
        $permission->save();
        $this->permissions = Permission::all();
        session()->flash('message', 'Permissão Atualizada com sucesso.');
        return;
    }

    #[on('deletePermission')]
    public function excludePermission($id)
    {
        $permission = Permission::where('id', $id)->first();
        if (!$permission) {
            session()->flash('error', 'Permissão não encontrada.');
            return;
        }
        $permission->delete();
        $this->permissions = Permission::all();
        session()->flash('message', 'Permissão deletada com sucesso.');
        return;
    }
}
