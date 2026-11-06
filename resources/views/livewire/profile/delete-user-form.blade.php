<?php

use App\Http\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = Auth::user();
        $logout();
        $user->delete();
        $this->redirect('/');
    }
}; ?>

<div class="d-none" aria-hidden="true"></div>
