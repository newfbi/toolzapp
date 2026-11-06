<?php

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Closure;

class RedirectIfIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @param string $guard
     * @return mixed
     */
    public function handle($request, Closure $next, string ...$guards)
    {
        // Se a requisição for do Livewire, não faça o redirecionamento.
        if (
            !empty($request->all()) or
            $request->components && $request->components[0]['updates'] or
            $request->components && $request->components[0]['calls']
        ) {
            return $next($request);
        }

        // Pegue o objeto do usuário, não apenas o ID
        $user = Auth::user();

        // Se o usuário for admin e a rota atual não for a de admin
        if (
            $user &&
            $user->hasPermission('administrador') &&
            Route::currentRouteName() == 'dashboard' &&
            Route::currentRouteName() != 'admin'
        ) {
            return redirect(AppServiceProvider::ADMIN);
        }

        return $next($request);
    }
}
