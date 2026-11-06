<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use App\Helpers\Utils;

class AppServiceProvider extends ServiceProvider
{
    public const ADMIN = '/administracao';
    public const DASHBOARD = '/dashboard';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Não precisa de bindings específicos, o Laravel resolve automaticamente
        // as dependências através da type-hinting nos construtores.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Event::listen(Login::class, function ($event) {
            try {
                $user = $event->user ?? null;
                if ($user && !empty($user->uuid)) {
                    $utils = new Utils();
                    $utils->checkPaymentUser($user->uuid);
                }
            } catch (\Throwable $e) {
                // silencioso: não bloqueia login
            }
        });
    }
}
