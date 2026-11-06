<?php

use App\Http\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();
        $this->form->authenticate();
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard'));
    }
}; ?>

<div>
    <div class="form-header">
        <h2>Bem-vindo de volta!</h2>
        <p>Faça seu login, de forma pratica e fecil. Gerencie seus Agentes.</p>
    </div>
    <div class="login-main">
        <p>Digite seu login e senha para acessar</p>
        @include('layouts.includes.errors-and-messages')
        <form class="theme-form" wire:submit="login">
            <div class="form-group">
                <label class="col-form-label">Email</label>
                <input class="form-control" type="email" wire:model="form.email" id="email" type="email"
                    name="email" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label class="col-form-label">Senha </label>
                <div class="form-input position-relative">
                    <input class="form-control" type="password" wire:model="form.password" id="password"
                        name="login[password]" required autocomplete="current-password">
                    <div class="show-hide"> <span class="show"></span></div>
                </div>
            </div>
            <div class="form-group mb-0">
                <a class="link" href="#">Esqueceu a Senha?</a>
                <div class="text-end mt-3">
                    <button class="btn btn-primary btn-block w-100" type="submit">Fazer Login</button>
                </div>
            </div>
            <p class="mt-4 mb-0 text-center">Ainda não tem um cadastro?<a class="ms-2"
                    href="{{ route('register') }}">Fazer Cadastro</a></p>
        </form>
    </div>
</div>
