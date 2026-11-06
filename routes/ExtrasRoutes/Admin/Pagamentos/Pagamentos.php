<?php

use App\Http\Livewire\Admin\Pagamentos\Pagamentos;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/pagamentos', Pagamentos::class)->name('admin.pagamentos');
});
