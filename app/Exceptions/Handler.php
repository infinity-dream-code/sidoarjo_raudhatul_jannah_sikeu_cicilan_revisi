<?php

namespace App\Exceptions;

use App\Support\PersistentLogin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof AuthenticationException) {
            try {
                if (!Auth::check()) {
                    PersistentLogin::restore();
                }
            } catch (Throwable) {
            }

            if (Auth::check()) {
                return redirect()->to($request->fullUrl());
            }

            if (PersistentLogin::hasCookie()) {
                return response()->view('errors.500', [], 500);
            }
        }

        if (PersistentLogin::isTransient($e) && $request->isMethod('GET') && !$request->expectsJson()) {
            if ($request->ajax()
                || $request->wantsJson()
                || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Gangguan sementara. Silakan coba lagi.',
                ], 503);
            }

            return response()->view('errors.500', [], 500);
        }

        if ($e instanceof TokenMismatchException) {
            try {
                if ($request->hasSession()) {
                    $request->session()->regenerateToken();
                }
            } catch (Throwable) {
            }

            if ($request->expectsJson()
                || $request->ajax()
                || $request->wantsJson()
                || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'ok' => false,
                    'csrf' => $request->hasSession() ? csrf_token() : null,
                ], 419);
            }

            return redirect()->back();
        }

        return parent::render($request, $e);
    }
}
