<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermissions
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        if (!$request->user() || !$request->user()->hasAnyPermission($permissions)) {
            abort(403, 'Acesso não autorizado.');
        }
        return $next($request);
    }
}
