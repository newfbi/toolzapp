<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Agents\{
    AgentsController,
    CreateAgentController,
    EditAgentController,
    ManageAgentController
};

Route::get('/agentes', AgentsController::class)->name('agentes');
Route::get('/agentes/create', CreateAgentController::class)->name('agents.create');
Route::get('/agentes/{agentId}/editar', EditAgentController::class)->name('agents.edit');
Route::get('/agentes/{agentId}/gerenciar', ManageAgentController::class)->name('agents.manage');
