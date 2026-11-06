<?php

use App\Http\Livewire\Admin\Usuarios\UserPermissions;
use App\Http\Livewire\Admin\Usuarios\UserAvailable;
use App\Http\Livewire\Admin\Usuarios\Usuarios;
use Illuminate\Support\Facades\Route;

Route::prefix('usuarios')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Usuarios::class)->name('admin.usuarios');
    Route::get('/{id}/permissoes', UserPermissions::class)->name('usuario.permissions');
    Route::any('/{id}/cargos', UserAvailable::class)->name('usuario.available');
});
