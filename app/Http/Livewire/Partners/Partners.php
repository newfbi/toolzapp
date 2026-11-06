<?php

namespace App\Http\Livewire\Partners;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Partners extends Component
{
    use WithPagination;

    public $filterSearch;
    public $perPage = 10;
    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        view()->share('title', 'Parceiros');
    }

    public function render()
    {
        $partners = $this->getPartnersQuery()->paginate($this->perPage);
        return view('livewire.partners.index', compact('partners'));
    }

    public function getPartnersQuery()
    {
        $user = Auth::user();
        $query = User::query()
            ->where('spon_id', $user->uuid)
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', ['franquia', 'supervisor', 'consultor']);
            })
            ->with(['plan', 'payments' => function ($q) {
                $q->whereIn('status', ['pago', 'creditado'])->orderBy('updated_at', 'desc');
            }]);

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
}
