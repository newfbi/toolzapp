<?php

namespace App\Http\Livewire\Admin\Usuarios;

use App\Helpers\Utils;
use App\Models\Payments;
use App\Models\Plans;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Component;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class Usuarios extends Component
{
    use \Livewire\WithPagination;

    public $userId;
    public $user_id;
    public $name;
    public $email;
    public $phone;
    public $password;
    public $plano;
    public $mensalidade;
    public $status;
    public $lead;
    public $filterSearch;
    public $perPage = 10;
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        view()->share('title', 'Usuários');
    }

    public function render()
    {
        $users = $this->getUsersQuery()->paginate($this->perPage);
        return view('livewire.admin.usuarios.index', compact('users'));
    }

    public function getUsersQuery()
    {
        $query = User::with([
            'payments' => function ($q) {
                $q->whereIn('status', ['pago', 'creditado'])->orderBy('updated_at', 'desc');
            },
            'plan',
            'agents',
            'chats',
            'convertedChats',
        ]);

        if (filled($this->filterSearch)) {
            $s = trim($this->filterSearch);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('phone', 'like', "%$s%");
            });
        }

        return $query;
    }

    public function updatedFilterSearch()
    {
        $this->resetPage();
    }

    public function updateItemModal($id = null)
    {
        $user = User::where('uuid', $id)->first();
        $this->user_id = $user->id;
        $this->userId = $user->uuid;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone;
        $this->status = $user->status;
        $this->plano = $user->plan ? $user->plan->tipo : null;
        $this->dispatch('show_up_modal');
    }

    public function updateUser()
    {
        $this->dispatch('fecharModal');
        $this->validate();
        $util = new Utils;
        $user = User::with(['payments' => function ($query) {
            $query->whereIn('status', ['pago', 'creditado'])
                ->whereNotIn('tipo', ['recarga'])
                ->where(function ($q) {
                    $q->whereIn('tipo', ['mensalidade', 'plan_consultor', 'plan_micro_franquia', 'plan_franquia'])
                        ->orWhere('tipo', 'like', 'plan_%');
                })
                ->orderBy('validade', 'desc')
                ->orderBy('updated_at', 'desc');
        }, 'plan'])->where('uuid', $this->userId)->first();

        if (!$user) {
            session()->flash('error', 'Usuário não encontrado.');
            return;
        }

        $lastPayment = $user->payments->first();
        $previousStatus = $user->status;
        $shouldCheckPayment = false;

        // Atualizar dados básicos do usuário
        $user->name = $this->name;
        $user->email = $this->email;
        $user->phone = preg_replace('/[^0-9]/', '', $this->phone);
        if (isset($this->password)) {
            $user->password = Hash::make($this->password);
        }
        $user->status = $this->status;
        $user->save();
        $user->refresh();

        // ✅ GERENCIAR PAGAMENTOS: Criar ou atualizar conforme necessário
        // Deixa checkPaymentUser processar plano, roles, status e APIs
        if ($this->status == 'active' && isset($this->plano) && isset($this->mensalidade) && $this->mensalidade > 0) {
            $currentPlanTipo = $user->plan ? $user->plan->tipo : null;
            $planChanged = $this->plano !== $currentPlanTipo;
            $expectedTipo = 'plan_' . $this->plano;

            // Se plano mudou OU não tem pagamento OU tipo do pagamento não corresponde ao plano
            if ($planChanged || !$lastPayment || ($lastPayment && $lastPayment->tipo !== $expectedTipo)) {
                // Criar novo pagamento com tipo correto do plano
                Payments::create([
                    'uuid' => Str::limit(Str::uuid(), 8, ''),
                    'user_id' => $user->uuid,
                    'spon_id' => $user->spon_id ?? null,
                    'billingType' => 'CREDIT_CARD',
                    'tipo' => $expectedTipo,
                    'data_compra' => now()->format('Y-m-d'),
                    'validade' => Carbon::now()->addDays(intval($this->mensalidade))->format('Y-m-d'),
                    'status' => 'creditado',
                    'valor' => 0,
                ]);
                $shouldCheckPayment = true;
            } elseif ($lastPayment && $lastPayment->tipo === $expectedTipo) {
                // Apenas atualizar validade do pagamento existente (mesmo plano)
                $lastPayment->validade = Carbon::now()->addDays(intval($this->mensalidade))->format('Y-m-d');
                $lastPayment->save();
                $shouldCheckPayment = true;
            }
        }

        // ✅ DESATIVAR: Ajustar validade do pagamento para ontem quando usuário é desativado
        if ($lastPayment && $this->status == 'disabled' && $previousStatus !== 'disabled') {
            $lastPayment->validade = Carbon::yesterday()->format('Y-m-d');
            $lastPayment->save();
            $shouldCheckPayment = true;
        }

        // ✅ PROCESSAR através de checkPaymentUser (que gerencia plano, roles, status, APIs)
        // Esta função vai:
        // - Verificar pagamentos válidos
        // - Associar plano ao usuário baseado no tipo de pagamento
        // - Aplicar roles do plano
        // - Ativar/desativar usuário conforme validade
        // - Desativar APIs se usuário for inativado
        if ($shouldCheckPayment || $previousStatus !== $this->status) {
            $util->checkPaymentUser($user->uuid);
        }

        $this->resetPage();
        session()->flash('success', "O Usuário foi atualizado com sucesso.");
        return;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|min:3|max:255',
            'email' => 'required|email|unique:users,email,' . $this->user_id,
            'password' => ['nullable', Rules\Password::defaults()],
            'phone' => 'nullable|string|min:11|max:15'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'O nome é obrigatorio. Verifique e tente novamente.',
            'email.required' => 'O Email é obrigatorio. Verifique e tente novamente.',
            'email.unique' => 'Este email já está sendo utilizado. Verifique e tente novamente.',
            'password' => 'Certifique-se de digitar uma senha Valida. Verifique e tente novamente',
            'phone.min' => 'O telefone deve conter no minimo 11 digitos. Verifique o numero digitado.',
        ];
    }
}
