<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Throwable;

class PersistentLogin
{
    public const COOKIE = 'sidoarjo_jannah';

    /** Alasan restore() terakhir gagal (untuk log & halaman gangguan). */
    public static ?string $lastFailure = null;

    public static ?string $lastError = null;

    /** Kegagalan yang tidak akan sembuh dengan refresh → harus login ulang. */
    private const PERMANENT_FAILURES = ['bad_payload', 'stamp_mismatch', 'users_mismatch', 'user_not_found'];

    public static function minutes(): int
    {
        $lifetime = (int) config('session.lifetime', 5256000);

        return $lifetime > 0 ? $lifetime : 5256000;
    }

    public static function set(?CyberKey $user = null): void
    {
        $user ??= Auth::user();
        if (!$user instanceof CyberKey) {
            return;
        }

        $payload = json_encode([
            'urut' => (int) $user->urut,
            'users' => (string) $user->users,
            'stamp' => self::stamp($user),
        ], JSON_UNESCAPED_UNICODE);

        Cookie::queue(cookie(
            self::COOKIE,
            $payload,
            self::minutes(),
            config('session.path', '/'),
            config('session.domain'),
            self::secure(),
            true,
            false,
            config('session.same_site', 'lax')
        ));
    }

    public static function clear(): void
    {
        Cookie::queue(Cookie::forget(
            self::COOKIE,
            config('session.path', '/'),
            config('session.domain')
        ));
    }

    public static function restore(): bool
    {
        self::$lastFailure = null;
        self::$lastError = null;

        if (Auth::check()) {
            return true;
        }

        $data = self::readCookiePayload();
        if (!is_array($data)) {
            self::$lastFailure = request()->cookie(self::COOKIE) ? 'bad_payload' : 'no_cookie';
            return false;
        }

        $urut = (int) ($data['urut'] ?? 0);
        if ($urut < 1) {
            self::$lastFailure = 'bad_payload';
            return false;
        }

        $user = self::findUser($urut);
        if (!$user instanceof CyberKey) {
            self::$lastFailure ??= 'user_not_found';
            return false;
        }

        if (!hash_equals(self::stamp($user), (string) ($data['stamp'] ?? ''))) {
            self::$lastFailure = 'stamp_mismatch';
            return false;
        }

        if (isset($data['users']) && (string) $data['users'] !== (string) $user->users) {
            self::$lastFailure = 'users_mismatch';
            return false;
        }

        // Tanpa Auth::login(): login() me-regenerate ID sesi, sehingga request AJAX paralel
        // masing-masing membuat sesi + token CSRF baru → 419 beruntun.
        try {
            $guard = Auth::guard();
            if (method_exists($guard, 'getName') && request()->hasSession()) {
                request()->session()->put($guard->getName(), $user->getAuthIdentifier());
            }
            $guard->setUser($user);
        } catch (Throwable) {
            Auth::setUser($user);
        }

        try {
            self::set($user);
        } catch (Throwable) {
        }

        return Auth::user() instanceof CyberKey;
    }

    public static function isTransient(Throwable $e): bool
    {
        $haystack = strtolower($e::class.' '.$e->getMessage());

        foreach ([
            'deadlock',
            'has gone away',
            'lost connection',
            'lock wait timeout',
            'unable to obtain lock',
            'unable to create lockable file',
            'resource temporarily unavailable',
            'serialization failure',
            'try restarting transaction',
            'no active transaction',
            'being used by another process',
            'failed to open stream',
            'permission denied',
            'unable to retrieve the session',
            'session store not set on request',
            'connection refused',
            'too many connections',
            'packets out of order',
            'sqlstate[40001]',
            'sqlstate[hy000] [2002]',
            'sqlstate[hy000] [2006]',
            'sqlstate[hy000]: general error: 1205',
            'sqlstate[hy000]: general error: 2006',
            'sqlstate[hy000]: general error: 2013',
            'please retry',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        $previous = $e->getPrevious();

        return $previous instanceof Throwable && self::isTransient($previous);
    }

    /**
     * Respon saat user punya cookie login tapi sesi tidak bisa dipulihkan.
     * Kegagalan permanen → hapus cookie & ke login; selain itu halaman gangguan + log.
     */
    public static function failedRestoreResponse(string $where, ?Throwable $e = null)
    {
        $reason = self::$lastFailure ?? 'unknown';
        $request = request();
        $permanent = in_array($reason, self::PERMANENT_FAILURES, true);
        $isAjax = $request->ajax()
            || $request->expectsJson()
            || $request->header('X-Requested-With') === 'XMLHttpRequest';

        try {
            Log::warning('[gangguan-sementara] ' . $where . ': ' . $reason, [
                'url' => $request->url(),
                'ip' => $request->ip(),
                'cookie_urut' => self::readCookiePayload()['urut'] ?? null,
                'error' => self::$lastError ?? $e?->getMessage(),
                'exception' => $e ? $e::class : null,
            ]);
        } catch (Throwable) {
        }

        if ($permanent) {
            self::clear();
        }

        if ($isAjax) {
            return response()->json([
                'ok' => false,
                'message' => $permanent
                    ? 'Sesi login sudah tidak berlaku, silakan login ulang.'
                    : 'Gangguan sementara. Silakan coba lagi.',
            ], $permanent ? 401 : 503);
        }

        if ($permanent) {
            return self::redirectToLogin('Sesi login sudah tidak berlaku, silakan login ulang.');
        }

        return response()->view('errors.500', ['code' => strtoupper($where . '-' . $reason)], 500);
    }

    /**
     * Ke halaman login sambil mengingat halaman terakhir, agar setelah login
     * user kembali ke halaman yang sedang dibuka (bukan ke beranda).
     */
    public static function redirectToLogin(?string $message = null)
    {
        $request = request();
        $intended = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();

        try {
            if ($request->hasSession() && $intended && !str_contains($intended, '/login')) {
                $request->session()->put('url.intended', $intended);
            }
        } catch (Throwable) {
        }

        $redirect = redirect()->route('login');

        return $message ? $redirect->with('error', $message) : $redirect;
    }

    public static function hasCookie(): bool
    {
        return self::readCookiePayload() !== null;
    }

    private static function readCookiePayload(): ?array
    {
        $raw = request()->cookie(self::COOKIE);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }

        try {
            $decrypted = app('encrypter')->decrypt($raw, false);
            $data = json_decode((string) $decrypted, true);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function findUser(int $urut): ?CyberKey
    {
        $attempts = 0;
        do {
            try {
                $user = CyberKey::query()->where('urut', $urut)->first();
                self::$lastFailure = null;
                self::$lastError = null;

                return $user;
            } catch (Throwable $e) {
                self::$lastFailure = 'db_error';
                self::$lastError = $e->getMessage();
                if (!self::isTransient($e) || $attempts >= 1) {
                    return null;
                }
                usleep(80000);
                $attempts++;
            }
        } while ($attempts < 2);

        return null;
    }

    private static function stamp(CyberKey $user): string
    {
        return hash_hmac(
            'sha256',
            $user->urut.'|'.$user->users.'|'.$user->password,
            (string) config('app.key')
        );
    }

    private static function secure(): bool
    {
        $configured = config('session.secure');
        if ($configured !== null) {
            return (bool) $configured;
        }

        return (bool) request()?->isSecure();
    }
}
