<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi menu/route master data lengkap untuk role helpdesk & super_admin saja.
 */
class EnsureFullMasterDataRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (method_exists($user, 'canAccessFullMasterData') && $user->canAccessFullMasterData()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk mengakses menu master data ini.',
            ], 403);
        }

        abort(403, 'Anda tidak memiliki izin untuk mengakses menu master data ini.');
    }
}
