<?php

namespace App\Http\Livewire\Financeiro;

use App\Helpers\Utils as Util;
use App\Models\CommissionLog;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Extrato extends Component
{
    public $user;
    public $clientes;
    public $settings;
    public $pagamentos;
    public $comissao;
    public $indication;
    public $totalMonth;
    public $expandedCommission = null;
    public $commissionNetworks = [];
    public $search = '';
    public $perPage = 50;
    public $hasMorePages = true;
    public $totalCount = 0;

    public function mount(Util $util)
    {
        view()->share('title', 'Gerenciamento e Extratos');
        $this->user = User::where('uuid', Auth::user()->uuid)->first();
        $this->settings = Settings::first();
        $this->loadPagamentos();
        // Comissão total do usuário
        $this->comissao = $util->getCommission($this->user->uuid);
        // Comissão total do usuário no mês
        $this->totalMonth = $util->getCommission($this->user->uuid, true);
        // Total de Clientes
        $this->clientes = 0;
    }

    public function toggleCommissionNetwork($commissionId)
    {
        // Garantir que seja um inteiro
        $commissionId = (int) $commissionId;

        if ($this->expandedCommission == $commissionId) {
            $this->expandedCommission = null;
            unset($this->commissionNetworks[$commissionId]);
        } else {
            $this->expandedCommission = $commissionId;
            // Buscar a comissão e calcular a rede
            $commission = $this->user->commission()
                ->where('id', $commissionId)
                ->first();
            if ($commission) {
                $this->commissionNetworks[$commissionId] = $this->calculateCommissionNetwork($commission);
            }
        }
    }

    public function updatedSearch($value)
    {
        $this->search = trim((string) $value);
        $this->perPage = 50;
        $this->hasMorePages = true;
        $this->loadPagamentos();
    }

    public function loadMore()
    {
        $this->perPage += 50;
        $this->loadPagamentos();
    }

    private function loadPagamentos(): void
    {
        $query = $this->user->commission()->where('status', 'enabled');
        $this->applySearchFilter($query);
        $query->orderBy('created_at', 'desc');
        $all = $query->get();
        $this->totalCount = $all->count();
        $this->hasMorePages = $this->totalCount > $this->perPage;
        $this->pagamentos = $all->take($this->perPage)->values();
    }

    private function applySearchFilter($query): void
    {
        $term = trim((string) $this->search);
        if ($term === '') {
            return;
        }
        $like = '%' . $term . '%';
        $numeric = preg_replace('/\D/', '', $term);
        $query->where(function ($q) use ($like, $numeric) {
            $q->where('servico', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhereHas('client', function ($c) use ($like) {
                    $c->where('name', 'like', $like);
                });
            if ($numeric !== '') {
                $q->orWhere('valor', 'like', '%' . $numeric . '%')
                    ->orWhere('valor_servico', 'like', '%' . $numeric . '%');
            }
        });
    }

    public function getCommissionNetwork($commissionId)
    {
        // Garantir que seja um inteiro
        $commissionId = (int) $commissionId;

        if (isset($this->commissionNetworks[$commissionId])) {
            return $this->commissionNetworks[$commissionId];
        }
        return [];
    }

    private function calculateCommissionNetwork($commission)
    {
        if (!$commission->payment_id) {
            return [];
        }

        // Buscar apenas os downlines do usuário atual (SEM incluir ele mesmo)
        $downlineUuids = Util::collectNetworkUserUuids($this->user, false);

        // Buscar todas as comissões relacionadas ao mesmo pagamento
        // Apenas as comissões dos downlines (não incluir o usuário atual, pois já está na linha principal)
        $allCommissions = CommissionLog::where('payment_id', $commission->payment_id)
            ->where('status', 'enabled')
            ->whereIn('user_id', $downlineUuids)
            ->where('user_id', '!=', $this->user->uuid) // Garantir que não inclua o usuário atual
            ->with(['user', 'sponsor', 'client', 'payment'])
            ->get();

        $network = [];

        // Adicionar apenas as comissões dos downlines
        foreach ($allCommissions as $comm) {
            if ($comm->user) {
                $network[] = [
                    'id' => $comm->id,
                    'user_id' => $comm->user_id,
                    'user_name' => $comm->user->name,
                    'sponsor_name' => $comm->sponsor->name ?? 'N/A',
                    'client_name' => $comm->client->name ?? 'N/A',
                    'tipo' => $comm->tipo,
                    'servico' => $comm->servico,
                    // Pagamentos não possuem relação direta com a tabela de
                    // clientes; manter a métrica em zero evita uma consulta
                    // inválida e preserva o contrato visual do extrato.
                    'clientes_count' => 0,
                    'valor' => $comm->valor,
                    'valor_servico' => $comm->valor_servico,
                    'status' => $comm->status,
                    'created_at' => $comm->created_at,
                ];
            }
        }

        // Ordenar por valor decrescente
        usort($network, function ($a, $b) {
            return $b['valor'] <=> $a['valor'];
        });

        return $network;
    }

    public function render()
    {
        return view('livewire.financeiro.extrato');
    }
}
