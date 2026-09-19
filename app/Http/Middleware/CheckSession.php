<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        if (!\Illuminate\Support\Facades\Auth::check()) {
            try {
                \App\Support\PersistentLogin::restore();
            } catch (\Throwable) {
            }
        }

        if (\Illuminate\Support\Facades\Auth::check() || session()->has('user')) {
            return $next($request);
        }

        // Cookie ada tapi session belum siap: coba restore sekali lagi (hindari 500 palsu)
        if (\App\Support\PersistentLogin::hasCookie()) {
            usleep(100000);
            try {
                \App\Support\PersistentLogin::restore();
            } catch (\Throwable) {
            }

            if (\Illuminate\Support\Facades\Auth::check() || session()->has('user')) {
                return $next($request);
            }

            // Masih gagal → halaman soft-retry (bukan putus login)
            return response()->view('errors.500', [], 500);
        }

        return redirect()->route('login');
    }
}
