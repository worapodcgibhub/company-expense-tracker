<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user whose account was deactivated while they were signed in
 * (or who signed in by a path that skips the password check, e.g. passkeys).
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'บัญชีของคุณถูกปิดการใช้งาน กรุณาติดต่อเจ้าของบริษัท';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, self::MESSAGE);
            }

            return redirect()->route('login')->with('status', self::MESSAGE);
        }

        return $next($request);
    }
}
