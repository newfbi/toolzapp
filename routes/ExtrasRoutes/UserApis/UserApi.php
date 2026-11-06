<?php

use App\Http\Livewire\UserApi\CreateApiController;
use Illuminate\Support\Facades\Route;

Route::get('/user/apis', CreateApiController::class)->name('apis.config');