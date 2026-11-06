<?php

use App\Http\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/');
    }
}; ?>

<nav class="d-none" aria-hidden="true"></nav>
