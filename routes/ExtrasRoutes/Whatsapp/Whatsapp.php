<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Whatsapp\WhatsappInstancesController;
use App\Http\Livewire\Whatsapp\WhatsappLayoutsController;
use App\Http\Controllers\Whatsapp\Instances\InstanceWebhookController;

Route::get('/whatsapp', WhatsappInstancesController::class)->name('whatsapp');
Route::get('/whatsapp/layouts', WhatsappLayoutsController::class)->name('whatsapp.layouts');

// Webhooks para instâncias específicas
//Route::post('/webhook/whatsapp/{instanceId}', [InstanceWebhookController::class, 'handleWebhook'])->name('whatsapp.webhook.instance');
