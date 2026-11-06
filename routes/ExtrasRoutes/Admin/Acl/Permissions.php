<?php

use App\Http\Livewire\Admin\Acl\PermissionRoles;
use App\Http\Livewire\Admin\Acl\Permissoes;
use Illuminate\Support\Facades\Route;

Route::prefix('permissoes')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Permissoes::class)->name('permissions');
    Route::get('/{id}/regras', PermissionRoles::class)->name('permissions.roles');
});
