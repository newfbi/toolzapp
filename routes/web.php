<?php

use App\Http\Controllers\Home\HomeController;
use App\Services\WhatsappSocketTokenService;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'HomePage.index')->name('home');
Route::get('/ref/{id}', [HomeController::class, 'Indication'])->name('afiliate.system');

Route::middleware('auth')->group(function () {
    Route::get('/whatsapp/socket-token', function (WhatsappSocketTokenService $tokens) {
        return response()->json([
            'token' => $tokens->issue(request()->user()),
            'expires_in' => 3600,
        ]);
    })->name('whatsapp.socket-token');

    require __DIR__ . '/ExtrasRoutes/Admin/Admin.php';
    require __DIR__ . '/ExtrasRoutes/Dashboard/dashboard.php';
    require __DIR__ . '/ExtrasRoutes/Agentes/agentes.php';
    require __DIR__ . '/ExtrasRoutes/Partners/partners.php';
    require __DIR__ . '/ExtrasRoutes/Chats/chats.php';
    require __DIR__ . '/ExtrasRoutes/CRM/crm.php';
    require __DIR__ . '/ExtrasRoutes/UserApis/UserApi.php';
    require __DIR__ . '/ExtrasRoutes/Whatsapp/Whatsapp.php';
    require __DIR__ . '/ExtrasRoutes/UserLists/UserLists.php';
    require __DIR__ . '/ExtrasRoutes/Sms/Sms.php';
    require __DIR__ . '/ExtrasRoutes/Profile/Profile.php';
    require __DIR__ . '/ExtrasRoutes/Planos/planos.php';
    require __DIR__ . '/ExtrasRoutes/Financeiro/Financeiro.php';
});

require __DIR__ . '/auth.php';
