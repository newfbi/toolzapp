<?php

use App\Http\Livewire\Admin\Acl\RoleAvailable;
use App\Http\Livewire\Admin\Acl\RolePermissions;
use App\Http\Livewire\Admin\Acl\Roles;
use Illuminate\Support\Facades\Route;

Route::prefix('regras')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Roles::class)->name('roles');
    Route::get('/{id}/permissoes', RolePermissions::class)->name('roles.permissions');
    Route::any('/{id}/cargos', RoleAvailable::class)->name('roles.available');
});
