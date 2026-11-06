<?php

namespace App\Http\Livewire\Financeiro;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\Saques as Retiradas;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Saques extends Component
{
    public $user;
    public $settings;
    public $retiradas;
    public $totalRetiradas;
    public $chave_pix;
    public $valor_saque;

    public function mount()
    {
        view()->share('title', 'Retiradas');
        $this->user = User::where('uuid', Auth::User()->uuid)->first();
        $this->settings = Settings::first();
        $this->retiradas = $this->user->Saques()->orderBy('created_at', 'desc')->get();
        $this->totalRetiradas = $this->user->Saques()->where(['tipo' => 'saque', 'status' => 'creditado'])->sum('valor');
    }

    public function render()
    {
        return view('livewire.financeiro.saques');
    }

    public function storeWithdraw(){
        $this->validate();

        $amount = round((float) $this->valor_saque, 2);
        if ($amount <= 0) {
            session()->flash('error', 'Informe um valor de retirada válido.');
            return;
        }

        // A validação e o débito acontecem na mesma instrução condicional;
        // duas requisições concorrentes não conseguem gastar o mesmo saldo.
        $debited = User::whereKey($this->user->getKey())
            ->where('saldo', '>=', $amount)
            ->decrement('saldo', $amount);

        if ($debited !== 1) {
            session()->flash('error', 'Saldo insuficiente para realizar a retirada.');
            return;
        }

        $is_uuid = Str::uuid();
        $uuid = Str::limit($is_uuid, 8, '');

        try {
            Retiradas::create([
                'uuid' => $uuid,
                'user_id' => $this->user->uuid,
                'chave_pix' => $this->chave_pix,
                'valor' => $amount,
                'tipo' => 'saque',
                'status' => 'pendente',
                'data' => Carbon::now()->format('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            // Se a criação do pedido falhar, não reter o saldo do usuário.
            User::whereKey($this->user->getKey())->increment('saldo', $amount);
            session()->flash('error', 'Não foi possível registrar a retirada. Tente novamente.');
            return;
        }

        $this->user->refresh();

        $this->retiradas = $this->user->Saques()->orderBy('created_at', 'desc')->get();
        $this->totalRetiradas = $this->user->Saques()->where('status', 'creditado')->sum('valor');
        $this->chave_pix = '';
        $this->valor_saque = '';

        session()->flash('success', 'Retirada solicitada com sucesso.');
        return;
    }

    public function rules()
    {
        return [
            'chave_pix' => ['required', 'string', 'min:8', 'max:255'],
            'valor_saque' => ['required', 'numeric', 'min:1'],
        ];
    }

    public function messages()
    {
        return [
            'chave_pix.required' => 'Sua chave Pix é obrigatório. Verifique e tente novamente.',
            'valor_saque.required' => 'O valor de retirada é obrigatório.',
            'valor_saque.numeric' => 'O valor de retirada deve ser numérico.',
        ];
    }
}
