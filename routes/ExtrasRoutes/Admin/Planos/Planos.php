<?php

use App\Http\Livewire\Admin\Planos\Planos;
use Illuminate\Support\Facades\Route;

Route::prefix('planos')->middleware('check.permissions:administrador')->group(function () {
    Route::get('/', Planos::class)->name('admin.planos');
});

