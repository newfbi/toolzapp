<?php

use App\Http\Livewire\UserLists\UserListsController;
use Illuminate\Support\Facades\Route;

Route::get('/user/lists', UserListsController::class)->name('user.lists');
