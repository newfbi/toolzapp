<?php

namespace App\Http\Livewire\Planos;

use App\Models\Plans;
use App\Models\Payments;
use App\Models\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class PlanosController extends Component
{
    public $plans;
    public $highlightId = null;

    public function mount($planId = null)
    {
        view()->share('title', 'Planos');

        $this->plans = Plans::query()->orderBy('price')->get();
        if ($planId) {
            $planId = intval($planId);
            $exists = $this->plans->contains('id', $planId);
            $this->highlightId = $exists ? $planId : null;
        }

        if (!$this->highlightId) {
            $this->highlightId = $this->plans->count()
                ? optional($this->plans->get(intdiv(max($this->plans->count() - 1, 0), 2)))->id
                : null;
        }
    }

    public function render()
    {
        $settings = Settings::first();
        return view('livewire.planos.index', [
            'plans' => $this->plans,
            'highlightId' => $this->highlightId,
            'settings' => $settings,
        ]);
    }

    public function contratar(int $planId)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login')->with('error', 'Faça login para contratar um plano.');
        }
        $plan = Plans::find($planId);
        if (!$plan) {
            return redirect()->back()->with('error', 'Plano não encontrado.');
        }

        $payment = new Payments();
        $payment->uuid = Str::limit(Str::uuid(), 8, '');
        $payment->user_id = $user->uuid;
        $payment->spon_id = $user->spon_id;
        $payment->reference = 'plan:' . $plan->id;
        $payment->product_name = $plan->name ?? 'Plano';

        $payment->tipo = 'mensalidade';
        $payment->valor = (float) ($plan->price ?? 0);
        $payment->status = 'pendente';
        $payment->save();

        return redirect()->route('checkout', ['paymentId' => $payment->uuid]);
    }
}
