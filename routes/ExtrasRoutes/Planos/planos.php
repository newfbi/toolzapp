<?php

use App\Http\Livewire\Planos\PlanosController;
use Illuminate\Support\Facades\Route;

Route::get('/planos-infos', PlanosController::class)->name('planos.index');
Route::get('/planos-infos/contratar/{planId}', PlanosController::class)
    ->where('planId', '[0-9]+')
    ->name('planos.contratar');
