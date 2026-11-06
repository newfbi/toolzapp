<?php

use App\Http\Livewire\Chats\ChatsController;
use App\Http\Livewire\Chats\ViewChatController;
use App\Http\Livewire\PersonalChat\PersonalChatController;
use App\Http\Livewire\LeadsQualification\LeadsQualificationController;
use Illuminate\Support\Facades\Route;

Route::get('/chats', ChatsController::class)->name('agents.chats');
Route::get('/chat/{chatUuid}', ViewChatController::class)->name('chats.view');
Route::get('/chat/agent/{agentId}', ViewChatController::class)->name('chats.create');

// Personal Chat Routes
Route::get('/personal-chat', PersonalChatController::class)->name('personal-chat.index');

// Leads Qualification Routes
Route::get('/leads-qualification', LeadsQualificationController::class)->name('leads.qualification');
