<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebHook\WebHookController;
use App\Http\Controllers\WebHook\ComprarSmsWebhookController;
use App\Http\Controllers\WebHook\SmsWebhookController;
use App\Http\Controllers\Whatsapp\Instances\InstanceWebhookController;

Route::post('/asaas/webhook', [WebhookController::class, 'handleAsaasWebhook'])->name('asaas.webhook');
Route::post('/ondapay/webhook', [WebhookController::class, 'handleOndaPayWebhook'])->name('ondapay.webhook');

Route::post('/webhook/hotmart', [WebHookController::class, 'handleHotmartWebhook']);
Route::post('/webhook/ticto', [WebhookController::class, 'handleTictoWebhook']);
Route::post('/webhook/greenn', [WebHookController::class, 'handleGreennWebhook']);

// Webhook externo da Z-API: fica no grupo API (sem CSRF) e usa uma assinatura
// HMAC na URL para impedir que terceiros injetem mensagens/status.
Route::post('/webhook/z-api/{instanceUuid}/{signature}', [InstanceWebhookController::class, 'handleZApiWebhook'])
    ->where('signature', '[A-Fa-f0-9]{64}')
    ->name('whatsapp.webhook.zapi');

// Webhook da ComprarSMS: a assinatura HMAC é validada no controller.
Route::post('/webhook/comprarsms', ComprarSmsWebhookController::class)
    ->name('comprarsms.webhook');

// URL genérica para futuros adaptadores SMS. A URL antiga permanece ativa.
Route::post('/webhook/sms/{provider}', SmsWebhookController::class)
    ->where('provider', '[A-Za-z0-9_-]+')
    ->name('sms.webhook');
