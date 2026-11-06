<?php

namespace App\Http\Livewire\Admin\Pagamentos;

use App\Helpers\Utils;
use App\Models\Payments;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class Pagamentos extends Component
{
    use \Livewire\WithPagination;

    public $filterSearch;
    public $perPage = 10;
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        view()->share('title', 'Painel de Pagamentos');
    }

    public function render()
    {
        $pagamentos = $this->getPagamentosQuery()->paginate($this->perPage);
        return view('livewire.admin.pagamentos.index', compact('pagamentos'));
    }

    public function getPagamentosQuery()
    {
        $query = Payments::query()->with('user')->orderBy('id', 'desc');

        if (filled($this->filterSearch)) {
            $s = trim($this->filterSearch);
            $query->where(function ($q) use ($s) {
                $q->where('tipo', 'like', "%$s%")
                    ->orWhere('status', 'like', "%$s%")
                    ->orWhere('billingType', 'like', "%$s%")
                    ->orWhereHas('user', function ($uq) use ($s) {
                        $uq->where('name', 'like', "%$s%")
                            ->orWhere('email', 'like', "%$s%");
                    });
            });
        }

        return $query;
    }

    public function updatedFilterSearch()
    {
        $this->resetPage();
    }

    #[on('detachSendPayment')]
    public function liberarPagamento($id)
    {
        $utils = new Utils;
        $settings = Settings::first();
        $pagamento = Payments::where('uuid', $id)->first();

        if (!$pagamento) {
            session()->flash('error', 'Pagamento não encontrado.');
            return;
        }

        $user = User::where('uuid', $pagamento->user_id)->first();
        if (!$user) {
            session()->flash('error', 'Usuário não encontrado para este pagamento.');
            return;
        }

        // ✅ RECARGA: Processar separadamente (não afeta plano/status do usuário)
        if ($pagamento->tipo == 'recarga') {
            $claimed = Payments::whereKey($pagamento->getKey())
                ->whereIn('status', ['aberto', 'pendente'])
                ->update(['status' => 'pago']);

            if ($claimed === 1) {
                // Calcular créditos baseado no token_value (mesma lógica do frontend e webhook)
                $tokenValue = floatval($settings->token_value ?? 0.09);

                $creditosParaAdicionar = $tokenValue > 0
                    ? floatval($pagamento->valor) / $tokenValue
                    : floatval($pagamento->valor);

                $user->increment('credits', $creditosParaAdicionar);
            }

            session()->flash('success', 'Recarga processada com sucesso!');
            $this->resetPage();
            return;
        }

        try {
            [$claimed, $processed, $planApplied] = DB::transaction(function () use ($pagamento, $user, $utils) {
                // PAGAMENTO DE PLANO/MENSALIDADE: reivindica a transição
                // apenas quando ainda está aberto/pendente.
                $nextStatus = $pagamento->billingType === 'PIX' ? 'pago' : 'creditado';
                $claimed = Payments::whereKey($pagamento->getKey())
                    ->whereIn('status', ['aberto', 'pendente'])
                    ->update(['status' => $nextStatus]);

                $pagamento->refresh();
                if (!in_array($pagamento->status, ['pago', 'creditado'], true)) {
                    return [$claimed, false, false];
                }

                // Também reprocessa pagamentos já liberados para reparar
                // vínculo de plano/ativação sem duplicar comissão.
                $processed = $utils->checkPaymentUser($user->uuid);
                $user->refresh()->load('plan');

                if (!$processed || !$user->plan) {
                    throw new \RuntimeException(
                        'O plano não foi encontrado ou não pôde ser aplicado ao usuário.'
                    );
                }

                return [$claimed, $processed, (bool) $user->plan];
            });
        } catch (\Throwable $e) {
            Log::error('Erro ao liberar/aplicar plano do pagamento', [
                'payment_id' => $pagamento->uuid,
                'user_id' => $user->uuid,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Não foi possível concluir a liberação do pagamento.');
            $this->resetPage();
            return;
        }

        if (!$processed || !$planApplied) {
            session()->flash('error', 'Pagamento liberado, mas o plano não foi encontrado ou não pôde ser aplicado. Verifique a referência do plano.');
            $this->resetPage();
            return;
        }

        // Comissão somente na primeira reivindicação, evitando duplicidade
        // quando o admin estiver reparando um pagamento já creditado.
        if ($claimed === 1) {
            Utils::setComission($pagamento->fresh());
        }

        session()->flash('success', 'Pagamento liberado, plano aplicado e usuário ativado com sucesso!');
        $this->resetPage();
        return;
    }

    #[on('detachCancelPayment')]
    public function cancelarPagamento($id)
    {
        $pagamento = Payments::where('uuid', $id)->first();
        $pagamento->status = 'cancelado';
        $pagamento->save();
        $pagamento->refresh();
        session()->flash('success', 'Pagamento cancelado com sucesso!');
        $this->resetPage();
        return;
    }
}
