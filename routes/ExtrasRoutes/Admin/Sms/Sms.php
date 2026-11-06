<?php

use App\Http\Livewire\Admin\Sms\SmsSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/administracao/sms', SmsSettingsController::class)
    ->middleware('check.permissions:administrador')
    ->name('admin.sms');
