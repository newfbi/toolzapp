<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Settings;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $settings = Settings::first();
        $sponId = Session::get('sponId');
        if (empty($sponId)) {
            $sponId = $settings->admin;
            Session::put('sponId', $sponId);
        }
        if (!Session::has('sponsorName')) {
            $sponsorName = User::where('uuid', $sponId)->value('name');
            if (!empty($sponsorName)) {
                Session::put('sponsorName', $sponsorName);
            }
        }
    }

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $settings = Settings::first();
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $sponId = Session::get('sponId');
        if (empty($sponId)) {
            $sponId = $settings->admin;
            Session::put('sponId', $sponId);
        }
        $validated['spon_id'] = $sponId;
        $validated['credits'] = $settings->credits_bonus;

        event(new Registered(($user = User::create($validated))));

        Auth::login($user);

        $this->redirect(route('dashboard'));
    }
}; ?>

<div>
    <div class="form-header">
        <h2>Seja Bem Vindo!</h2>
        <p>Não tem uma conta? Crie uma nova agora. Leva menos de um minuto.</p>
    </div>
    <div class="login-main">
        <p>Preencha todos os dados com atenção</p>
        @if (Session::has('sponId'))
            <div class="sponsor-info">
                <strong>Você está sendo convidado por:<br> <span
                        class="sponsor-name">{{ Session::get('sponsorName') }}</span></strong>
            </div>
        @endif
        @include('layouts.includes.errors-and-messages')
        <form class="theme-form" wire:submit="register">
            <div class="form-group">
                <label class="pt-0 col-form-label">Nome Completo</label>
                <input class="form-control" wire:model="name" id="name" name="name" required autofocus
                    autocomplete="name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div class="form-group">
                <label class="col-form-label">Endereço de Email</label>
                <input class="form-control" wire:model="email" id="email" type="email" name="email" required
                    autocomplete="username">
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <div class="form-group">
                <label class="col-form-label">Senha</label>
                <div class="position-relative form-input">
                    <input class="form-control" wire:model="password" id="password" type="password" name="password"
                        required autocomplete="new-password">
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    <div class="show-hide"><span class="show"></span></div>
                </div>
            </div>
            <div class="form-group">
                <label class="col-form-label">Confirme sua Senha</label>
                <div class="position-relative form-input">
                    <input class="form-control" type="password" wire:model="password_confirmation"
                        id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                </div>
            </div>
            <div class="form-group mb-0">
                <button class="btn-block w-100 btn btn-primary" type="submit">Fazer Cadastro</button>
            </div>
            <p class="mt-4 mb-0">Já tem um Cadastro?<a class="ms-2" href="{{ route('login') }}">Fazer Login</a></p>
        </form>
    </div>
</div>
