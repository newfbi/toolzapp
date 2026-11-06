<?php

use App\Http\Livewire\Admin\Commissions\Commissions;
use Illuminate\Support\Facades\Route;

Route::prefix('comissoes')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Commissions::class)->name('admin.comissoes');
});

