<?php

use App\Http\Livewire\Profile\Profile;
use Illuminate\Support\Facades\Route;

Route::get('/perfil', Profile::class)->name('profile');
Route::get('/profile', Profile::class)->name('profile.legacy');
