<?php

use App\Http\Livewire\Financeiro\Checkout;
use App\Http\Livewire\Financeiro\Extrato;
use App\Http\Livewire\Financeiro\Mensalidade;
use App\Http\Livewire\Financeiro\Recarga;
use App\Http\Livewire\Financeiro\Pagamentos;
use App\Http\Livewire\Financeiro\Saques;
use Illuminate\Support\Facades\Route;

Route::get('/extratos', Extrato::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('extratos');
Route::get('/pagamentos', Pagamentos::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('pagamentos');
Route::get('/mensalidade', Mensalidade::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('mensalidade');
Route::get('/recarga', Recarga::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('recarga');
Route::get('/checkout/{paymentId}', Checkout::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('checkout');
Route::get('/saques', Saques::class)->middleware('check.permissions:franquia,supervisor,consultor,cliente')->name('saques');
