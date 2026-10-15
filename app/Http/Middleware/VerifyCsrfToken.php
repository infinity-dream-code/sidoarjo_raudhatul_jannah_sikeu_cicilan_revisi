<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    /**
     * Token di halaman bisa basi kalau sesi dibuat ulang (file sesi hilang / dipulihkan
     * dari cookie login). Request yang terbukti berasal dari domain aplikasi sendiri
     * (header Origin/Referer) tetap diterima agar user tidak kena 419.
     */
    protected function tokensMatch($request): bool
    {
        if (parent::tokensMatch($request)) {
            return true;
        }

        return $this->isSameOriginRequest($request);
    }

    private function isSameOriginRequest(Request $request): bool
    {
        $source = $request->headers->get('Origin');
        if (!$source || $source === 'null') {
            $source = $request->headers->get('Referer');
        }
        if (!$source) {
            return false;
        }

        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        if ($sourceHost === '') {
            return false;
        }

        $allowedHosts = array_filter([
            strtolower($request->getHost()),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]);

        return in_array($sourceHost, $allowedHosts, true);
    }
}
