<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($authenticated = $request->user()) {
            $user = User::query()->find($authenticated->getAuthIdentifier());
            $staleSession = $request->hasSession() && (int) $request->session()->get('auth_version', 0) !== $user?->auth_version;
            if (! $user?->active || $staleSession) {
                if ($request->hasSession()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                throw new AuthenticationException;
            }
        }

        return $next($request);
    }
}
