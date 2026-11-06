<?php

use App\Http\Livewire\Admin\Clientes\Clientes;
use Illuminate\Support\Facades\Route;

Route::prefix('clientes')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Clientes::class)->name('admin.clientes');
});
