<?php

namespace App\Http\Livewire\Admin\Comunicados;

use Livewire\Component;
use App\Models\Avisos;

class Comunicados extends Component
{
    public $aviso;
    public $message;
    public $status;
    public $title;
    public function mount()
    {
        view()->share('title',"Gerenciar Avisos");
        $this->aviso = Avisos::first();
    }

    public function render()
    {
        return view('livewire.admin.comunicados.comunicados');
    }

    public function store()
    {
        $this->aviso->title = $this->title;
        $this->aviso->message = $this->message;
        $this->aviso->save();
        $this->aviso->refresh();
        $this->reset('title', 'message');

        session()->flash('success', 'Aviso criado com sucesso');
    }

    public function updateStatus($status)
    {
        $this->aviso->status = $status;
        $this->aviso->save();
        $this->aviso->refresh();

        session()->flash('success', 'Status atualizado com sucesso');
    }
}
