<?php

namespace App\Http\Livewire\Financeiro;

use App\Helpers\Utils as Util;
use App\Models\Payments;
use App\Models\ClientsList;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Pagamentos extends Component
{
    public $user;
    public $perPage = 5;
    public $page = 1;
    public $hasMorePages = true;

    public function mount()
    {
        View::share('title', 'Pagamentos');
        $this->user = User::where('uuid', Auth::User()->uuid)->first();
    }

    public function render()
    {
        $util = new Util();
        $pagamentos = Payments::where(['user_id' => $this->user->uuid])->where('tipo', '!=', 'limpa_nome')
            ->orderBy('id', 'desc')->get(); // Retorna todos os pagamentos filtrados
        $itemsToShow = $this->page * $this->perPage;
        $this->hasMorePages = $pagamentos->count() > $itemsToShow;
        $pagamentos = $util->Paginate($pagamentos, $this->perPage);
        $totalCount = $pagamentos->count();
        return view('livewire.financeiro.pagamentos', compact('pagamentos', 'totalCount'));
    }

    public function requestCancel($id)
    {
        $payment = Payments::where('uuid', $id)
            ->where('user_id', $this->user->uuid)
            ->first();
        if (!$payment) {
            session()->flash('error', 'Pagamento não encontrado.');
            return;
        }
        if (!in_array($payment->status, ['aberto', 'pendente'])) {
            session()->flash('error', 'Este pagamento não pode ser cancelado.');
            return;
        }

        $this->dispatch('showCancelConfirm', ['id' => $id]);
    }

    #[On('handleCancelPayment')]
    public function cancelPayment($id)
    {
        $payment = Payments::where('uuid', $id)
            ->where('user_id', $this->user->uuid)
            ->first();
        if (!$payment) {
            session()->flash('error', 'Pagamento não encontrado.');
            return;
        }
        if (!in_array($payment->status, ['aberto', 'pendente'])) {
            session()->flash('error', 'Este pagamento não pode ser cancelado.');
            return;
        }

        $payment->status = 'cancelado';
        $payment->save();

        session()->flash('success', 'Pagamento cancelado com sucesso.');
        $this->dispatch('cancelConcluid');
        return;
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function debugInfo()
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'hasMorePages' => $this->hasMorePages,
            'loading' => $this->loading,
            'clientes_count' => $this->clientes->count() ?? 0
        ];
    }
}
