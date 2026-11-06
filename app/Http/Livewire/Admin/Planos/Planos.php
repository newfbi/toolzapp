<?php

namespace App\Http\Livewire\Admin\Planos;

use App\Models\Plans;
use App\Models\Traits\Role as RoleModel;
use Livewire\Component;
use Livewire\Attributes\On;

class Planos extends Component
{
    use \Livewire\WithPagination;

    public $filterSearch;
    public $perPage = 10;
    protected $paginationTheme = 'bootstrap';

    public $planId;
    public $name;
    public $credits;
    public $price;
    public $commission;
    public $indication;
    public $mensalidade;
    public $agentes;
    public $instances;
    public $team_members;
    public $img;
    public $status;
    public $roles = [];
    public $allRoles = [];

    public function mount()
    {
        view()->share('title', 'Planos');
        $this->allRoles = RoleModel::query()->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function render()
    {
        $plans = $this->getPlansQuery()->paginate($this->perPage);
        return view('livewire.admin.planos.index', compact('plans'));
    }

    public function getPlansQuery()
    {
        $query = Plans::query();
        if (filled($this->filterSearch)) {
            $s = trim($this->filterSearch);
            $query->where(function ($q) use ($s) {
                $q->where('tipo', 'like', "%$s%")
                    ->orWhere('credits', 'like', "%$s%")
                    ->orWhere('price', 'like', "%$s%");
            });
        }
        return $query->orderBy('id', 'desc');
    }

    public function updatedFilterSearch()
    {
        $this->resetPage();
    }

    public function showCreateModal()
    {
        $this->reset(['planId', 'name', 'credits', 'price', 'commission', 'indication', 'mensalidade', 'agentes', 'instances', 'team_members', 'img', 'status', 'roles']);
        $this->dispatch('show_create_plan_modal');
    }

    public function showEditModal($id)
    {
        $plan = Plans::find($id);
        if (!$plan) return;
        $this->planId = $plan->id;
        $this->name = $plan->name ?? null;
        $this->credits = $plan->credits;
        $this->price = $plan->price;
        $this->commission = $plan->commission ?? null;
        $this->indication = $plan->indication ?? null;
        $this->mensalidade = $plan->mensalidade ?? null;
        $this->agentes = $plan->agentes ?? null;
        $this->instances = $plan->instances ?? null;
        $this->team_members = $plan->team_members ?? null;
        $this->img = $plan->img ?? null;
        $this->status = $plan->status ?? null;
        $this->roles = is_array($plan->roles ?? null) ? $plan->roles : (filled($plan->roles) ? json_decode($plan->roles, true) : []);
        $this->dispatch('show_edit_plan_modal');
    }

    public function createPlan()
    {
        $data = $this->validate($this->rules());
        if (isset($data['roles'])) {
            if (is_string($data['roles'])) {
                $data['roles'] = collect(explode(',', $data['roles']))->map(fn($r) => trim($r))->filter()->values()->all();
            }
            if (is_array($data['roles'])) {
                $data['roles'] = json_encode($data['roles']);
            }
        }
        Plans::create($data);
        $this->dispatch('hide_create_plan_modal');
        $this->resetPage();
        session()->flash('success', 'Plano criado com sucesso.');
    }

    public function updatePlan()
    {
        $plan = Plans::find($this->planId);
        if (!$plan) return;
        $data = $this->validate($this->rules());
        if (isset($data['roles'])) {
            if (is_string($data['roles'])) {
                $data['roles'] = collect(explode(',', $data['roles']))->map(fn($r) => trim($r))->filter()->values()->all();
            }
            if (is_array($data['roles'])) {
                $data['roles'] = json_encode($data['roles']);
            }
        }
        $plan->update($data);
        $this->dispatch('hide_edit_plan_modal');
        session()->flash('success', 'Plano atualizado com sucesso.');
    }

    #[On('deletePlan')]
    public function deletePlan($id)
    {
        $plan = Plans::find($id);
        if (!$plan) return;
        $plan->delete();
        session()->flash('success', 'Plano removido com sucesso.');
    }

    public function rules()
    {
        return [
            'name' => 'required|string|min:2|max:120',
            'credits' => 'required|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'commission' => 'nullable|numeric|min:0',
            'indication' => 'nullable|numeric|min:0',
            'mensalidade' => 'nullable|integer|min:0',
            'agentes' => 'nullable|integer|min:0',
            'instances' => 'nullable|integer|min:0',
            'team_members' => 'nullable|integer|min:0',
            'img' => 'nullable|string|min:2',
            'status' => 'required|in:enabled,disabled,canceled',
            'roles' => 'nullable|array',
        ];
    }
}
