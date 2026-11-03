<?php

class Config
{
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(string $rootPath): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = $rootPath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            self::$env = self::parseEnvFile($envFile);
        }

        $tz = self::get('APP_TIMEZONE', 'Asia/Jakarta');
        date_default_timezone_set($tz);
        self::$loaded = true;
    }

    private static function parseEnvFile(string $path): array
    {
        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);
            $result[$key] = $value;
        }

        return $result;
    }

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$env) && self::$env[$key] !== '') {
            return self::$env[$key];
        }
        $fromServer = getenv($key);
        if ($fromServer !== false && $fromServer !== '') {
            return $fromServer;
        }
        return $default;
    }

    public static function smtpConfigured(): bool
    {
        return count(self::smtpAccounts()) > 0;
    }

    /**
     * Pool SMTP: SMTP_ACCOUNTS JSON, atau SMTP1_*..SMTP3_*, atau SMTP_* lama.
     *
     * @return list<array{id:string,host:string,port:int,secure:string,user:string,pass:string,from_email:string,from_name:string}>
     */
    public static function smtpAccounts(): array
    {
        $accounts = [];

        $json = trim((string) self::get('SMTP_ACCOUNTS', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $account = self::normalizeSmtpAccount($row, (string) ($i + 1));
                    if ($account !== null) {
                        $accounts[] = $account;
                    }
                }
            }
        }

        if ($accounts === []) {
            for ($i = 1; $i <= 3; $i++) {
                $account = self::normalizeSmtpAccount([
                    'host' => self::get("SMTP{$i}_HOST", self::get('SMTP_HOST', 'smtp.gmail.com')),
                    'port' => self::get("SMTP{$i}_PORT", self::get('SMTP_PORT', 587)),
                    'secure' => self::get("SMTP{$i}_SECURE", self::get('SMTP_SECURE', 'tls')),
                    'user' => self::get("SMTP{$i}_USER"),
                    'pass' => self::get("SMTP{$i}_PASS"),
                    'from_email' => self::get("SMTP{$i}_FROM_EMAIL", self::get("SMTP{$i}_USER")),
                    'from_name' => self::get("SMTP{$i}_FROM_NAME", self::get('MAIL_FROM_NAME', 'Email Broadcaster')),
                ], (string) $i);
                if ($account !== null) {
                    $accounts[] = $account;
                }
            }
        }

        if ($accounts === []) {
            $legacy = self::normalizeSmtpAccount([
                'host' => self::get('SMTP_HOST', 'smtp.gmail.com'),
                'port' => self::get('SMTP_PORT', 587),
                'secure' => self::get('SMTP_SECURE', 'tls'),
                'user' => self::get('SMTP_USER'),
                'pass' => self::get('SMTP_PASS'),
                'from_email' => self::get('MAIL_FROM_EMAIL', self::get('SMTP_USER')),
                'from_name' => self::get('MAIL_FROM_NAME', 'Email Broadcaster'),
            ], '1');
            if ($legacy !== null) {
                $accounts[] = $legacy;
            }
        }

        return $accounts;
    }

    public static function smtpQuotaPerAccount(): int
    {
        return max(1, (int) self::get('SMTP_QUOTA_PER_ACCOUNT', 20));
    }

    public static function smtpQuotaWindowSeconds(): int
    {
        return max(60, (int) self::get('SMTP_QUOTA_WINDOW_SECONDS', 600));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:string,host:string,port:int,secure:string,user:string,pass:string,from_email:string,from_name:string}|null
     */
    private static function normalizeSmtpAccount(array $row, string $fallbackId): ?array
    {
        $user = trim((string) ($row['user'] ?? $row['SMTP_USER'] ?? ''));
        $pass = (string) ($row['pass'] ?? $row['password'] ?? $row['SMTP_PASS'] ?? '');
        $fromEmail = trim((string) ($row['from_email'] ?? $row['from'] ?? $row['MAIL_FROM_EMAIL'] ?? $user));
        $host = trim((string) ($row['host'] ?? $row['SMTP_HOST'] ?? 'smtp.gmail.com'));

        if ($user === '' || $pass === '' || $fromEmail === '' || $host === '') {
            return null;
        }

        return [
            'id' => (string) ($row['id'] ?? $fallbackId),
            'host' => $host,
            'port' => (int) ($row['port'] ?? $row['SMTP_PORT'] ?? 587),
            'secure' => strtolower((string) ($row['secure'] ?? $row['SMTP_SECURE'] ?? 'tls')),
            'user' => $user,
            'pass' => $pass,
            'from_email' => $fromEmail,
            'from_name' => (string) ($row['from_name'] ?? $row['MAIL_FROM_NAME'] ?? 'Email Broadcaster'),
        ];
    }

    public static function defaultPaymentGuide(): string
    {
        return (string) self::get(
            'DEFAULT_PAYMENT_GUIDE',
            "PANDUAN PEMBAYARAN:\n1. Transfer ke Virtual Account (VA) yang tertera\n2. Atau klik Link Pembayaran pada email\n3. Simpan bukti pembayaran"
        );
    }

    public static function organizationName(): string
    {
        return (string) self::get('ORGANIZATION_NAME', 'Yayasan Asasi Indonesia');
    }

    /** Absolute URL fallback (HTTPS public URL). Prefer CID embed via mailLogoPath(). */
    public static function mailLogoUrl(): string
    {
        $logo = trim((string) self::get('MAIL_LOGO_URL', 'logo.png'));
        if ($logo === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }
        $base = rtrim((string) self::get('APP_BASE_URL', ''), '/');
        return $base !== '' ? ($base . '/' . ltrim($logo, '/')) : ltrim($logo, '/');
    }

    /** Local filesystem path to logo for CID embed (works in Gmail). */
    public static function mailLogoPath(string $rootPath): ?string
    {
        $logo = trim((string) self::get('MAIL_LOGO_URL', 'logo.png'));
        if ($logo === '' || preg_match('#^https?://#i', $logo)) {
            // If absolute URL, still try default local logo.png for embed
            $candidate = $rootPath . DIRECTORY_SEPARATOR . 'logo.png';
            return is_file($candidate) ? $candidate : null;
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($logo, '/\\'));
        $candidate = $rootPath . DIRECTORY_SEPARATOR . $relative;
        if (is_file($candidate)) {
            return $candidate;
        }

        $fallback = $rootPath . DIRECTORY_SEPARATOR . 'logo.png';
        return is_file($fallback) ? $fallback : null;
    }

    public static function mailLogoCid(): string
    {
        return 'ict-logo';
    }
}
