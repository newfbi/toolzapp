<?php

use App\Http\Livewire\Admin\Admin;
use Illuminate\Support\Facades\Route;

Route::get('/administracao',  Admin::class)->middleware('check.permissions:administrador')->name('admin');
require __DIR__ . '/Acl/Permissions.php';
require __DIR__ . '/Acl/Roles.php';
require __DIR__ . '/Usuarios/Usuarios.php';
require __DIR__ . '/Pagamentos/Pagamentos.php';
require __DIR__ . '/Commissions/Commissions.php';
require __DIR__ . '/Planos/Planos.php';
require __DIR__ . '/Whatsapp/Whatsapp.php';
require __DIR__ . '/Sms/Sms.php';
