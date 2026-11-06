<?php

namespace App\Http\Livewire\Admin\Commissions;

use App\Models\CommissionLog;
use Livewire\Component;
use Livewire\WithPagination;

class Commissions extends Component
{
    use WithPagination;

    public $filterSearch;
    public $perPage = 10;
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        view()->share('title', 'Admin - Comissões');
    }

    public function render()
    {
        $commissions = $this->getCommissionsQuery()->paginate($this->perPage);
        return view('livewire.admin.commissions.index', compact('commissions'));
    }

    public function getCommissionsQuery()
    {
        $query = CommissionLog::query()
            ->with(['user', 'client', 'sponsor', 'payment'])
            ->orderBy('id', 'desc');

        if (filled($this->filterSearch)) {
            $s = trim($this->filterSearch);
            $query->where(function ($q) use ($s) {
                $q->where('tipo', 'like', "%$s%")
                    ->orWhere('servico', 'like', "%$s%")
                    ->orWhere('status', 'like', "%$s%")
                    ->orWhereHas('user', function ($uq) use ($s) {
                        $uq->where('name', 'like', "%$s%")
                            ->orWhere('email', 'like', "%$s%");
                    })
                    ->orWhereHas('client', function ($cq) use ($s) {
                        $cq->where('name', 'like', "%$s%")
                            ->orWhere('email', 'like', "%$s%");
                    })
                    ->orWhereHas('sponsor', function ($sq) use ($s) {
                        $sq->where('name', 'like', "%$s%")
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
}
