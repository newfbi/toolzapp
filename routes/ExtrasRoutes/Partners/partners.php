<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Partners\Partners;

Route::get('/parceiros', Partners::class)->name('parceiros');
