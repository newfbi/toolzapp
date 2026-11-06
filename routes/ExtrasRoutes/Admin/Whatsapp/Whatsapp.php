<?php

use App\Http\Livewire\Admin\WhatsappInstancesAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/administracao/whatsapp', WhatsappInstancesAdminController::class)
    ->middleware('check.permissions:administrador')
    ->name('admin.whatsapp');
