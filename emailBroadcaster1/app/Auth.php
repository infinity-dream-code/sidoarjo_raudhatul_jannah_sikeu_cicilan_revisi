<?php

class Auth
{
    public static function check(): void
    {
        $token = Config::get('API_TOKEN', '');
        if ($token === null || $token === '') {
            return;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        $provided = '';
        if (stripos($header, 'Bearer ') === 0) {
            $provided = trim(substr($header, 7));
        }

        if ($provided === '' && isset($_SERVER['HTTP_X_API_TOKEN'])) {
            $provided = trim((string) $_SERVER['HTTP_X_API_TOKEN']);
        }

        if ($provided === '' && isset($_GET['token'])) {
            $provided = trim((string) $_GET['token']);
        }

        if (!hash_equals((string) $token, $provided)) {
            json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
    }
}
