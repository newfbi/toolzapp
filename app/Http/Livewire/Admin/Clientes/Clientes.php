<?php

namespace App\Http\Livewire\Admin\Clientes;

use App\Models\Clients;
use App\Models\DocumentUser;
use App\Services\ClicksignService;
use Livewire\Component;

class Clientes extends Component
{
    public $clientes;

    public function mount()
    {
        view()->share('title', 'Admin - Clientes');
        $this->clientes = Clients::query()->orderBy('id', 'desc')->get();
    }

    public function render()
    {
        return view('livewire.admin.clientes.clientes');
    }

    public function getContrato($id)
    {
        $clickSign = new ClicksignService();
        $document = DocumentUser::where('uuid', $id)->first();
        if (!$document) {
            session()->flash('error', 'Documento não encontrado!');
            return;
        }

        $document_pdf = $clickSign->getSignerDocument(
            $document->envelop_id,
            $document->document_id,
        );

        if (isset($document_pdf['error'])) {
            session()->flash('error', 'Erro ao gerar o contrato! Verifique se o cliente possui o contrato assinado.');
            return;
        }
        return redirect()->to($document_pdf);
    }
}
