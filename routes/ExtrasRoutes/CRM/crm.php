<?php

use App\Http\Livewire\Crm\CrmController;
use Illuminate\Support\Facades\Route;

Route::get('/crm', CrmController::class)->name('crm.index');