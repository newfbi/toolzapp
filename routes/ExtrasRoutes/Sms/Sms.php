<?php

use App\Http\Livewire\Sms\SmsLayoutsController;
use Illuminate\Support\Facades\Route;

Route::get('/sms/layouts', SmsLayoutsController::class)->name('sms.layouts');
