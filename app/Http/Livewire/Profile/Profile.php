<?php

namespace App\Http\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Profile extends Component
{
    public $user;
    public $email;
    public $password;
    public $cpf;
    public $phone;
    public $escritorio;
    public $rede_social;

    public function mount()
    {
        view()->share('title', 'Gerenciar Perfil');
        $this->user = Auth::user();
        $this->email = $this->user->email;
        $this->cpf = $this->user->cpf;
        $this->phone = $this->user->phone;
        $this->escritorio = $this->user->escritorio ?? '';
        $this->rede_social = $this->user->rede_social ?? '';
    }

    public function render()
    {
        return view('livewire.profile.profile');
    }

    public function updateProfile()
    {
        $this->cpf = preg_replace('/\D/', '', (string) $this->cpf);
        $this->phone = preg_replace('/\D/', '', (string) $this->phone);

        $rules = [
            'cpf' => ['required', 'cpf'],
            'phone' => ['required', 'digits_between:10,11'],
            'escritorio' => 'nullable|string|max:255',
            'rede_social' => 'nullable|string|max:255',
        ];

        if (!empty($this->password)) {
            $rules['password'] = 'min:6';
        }

        $messages = [
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.cpf' => 'CPF inválido.',
            'phone.required' => 'O Telefone é obrigatório.',
            'phone.digits_between' => 'Telefone deve ter 10 ou 11 dígitos.',
            'password.min' => 'A senha deve ter no mínimo 6 caracteres.',
        ];

        $this->validate($rules, $messages);

        $this->user->cpf = $this->cpf;
        $this->user->phone = $this->phone;
        $this->user->escritorio = $this->escritorio;
        $this->user->rede_social = $this->rede_social;

        if (!empty($this->password)) {
            $this->user->password = Hash::make($this->password);
        }

        $this->user->save();

        $this->password = '';
        session()->flash('message', 'Perfil atualizado com sucesso!');
        return;
    }
}
