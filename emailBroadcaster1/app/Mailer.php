<?php

/**
 * Minimal SMTP client (AUTH LOGIN + STARTTLS/SSL) — tanpa Composer.
 * Mendukung inline image (CID) agar logo tampil di Gmail.
 */
class Mailer
{
    /**
     * @param array<int, array{path:string,cid:string,name?:string}> $inlineImages
     * @param array{host?:string,port?:int,secure?:string,user?:string,pass?:string,from_email?:string,from_name?:string}|null $account
     */
    public static function send(
        string $to,
        string $subject,
        string $html,
        string $text = '',
        array $inlineImages = [],
        ?array $account = null
    ): void {
        if ($account === null) {
            $accounts = Config::smtpAccounts();
            $account = $accounts[0] ?? null;
        }

        if ($account === null) {
            throw new RuntimeException('SMTP belum dikonfigurasi. Isi SMTP1_* / SMTP_* di file .env');
        }

        $host = (string) ($account['host'] ?? Config::get('SMTP_HOST', 'smtp.gmail.com'));
        $port = (int) ($account['port'] ?? Config::get('SMTP_PORT', 587));
        $secure = strtolower((string) ($account['secure'] ?? Config::get('SMTP_SECURE', 'tls')));
        $user = (string) ($account['user'] ?? '');
        $pass = (string) ($account['pass'] ?? '');
        $fromEmail = (string) ($account['from_email'] ?? $user);
        $fromName = (string) ($account['from_name'] ?? Config::get('MAIL_FROM_NAME', 'Email Broadcaster'));

        if ($user === '' || $pass === '' || $fromEmail === '') {
            throw new RuntimeException('SMTP account tidak lengkap (user/pass/from).');
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException("Gagal konek SMTP: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 30);

        try {
            self::expect($fp, [220]);
            $ehloHost = self::ehloName();
            self::command($fp, 'EHLO ' . $ehloHost, [250]);

            if ($secure === 'tls') {
                self::command($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS gagal');
                }
                self::command($fp, 'EHLO ' . $ehloHost, [250]);
            }

            self::command($fp, 'AUTH LOGIN', [334]);
            self::command($fp, base64_encode($user), [334]);
            self::command($fp, base64_encode($pass), [235]);

            self::command($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);

            if ($text === '') {
                $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n"], $html));
            }

            $altBoundary = 'alt_' . bin2hex(random_bytes(8));
            $relatedBoundary = 'rel_' . bin2hex(random_bytes(8));
            $hasInline = count($inlineImages) > 0;

            $altBody = '';
            $altBody .= '--' . $altBoundary . "\r\n";
            $altBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $altBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $altBody .= chunk_split(base64_encode($text)) . "\r\n";
            $altBody .= '--' . $altBoundary . "\r\n";
            $altBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $altBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $altBody .= chunk_split(base64_encode($html)) . "\r\n";
            $altBody .= '--' . $altBoundary . "--\r\n";

            $replyTo = trim((string) Config::get('MAIL_REPLY_TO', $fromEmail));
            $headers = [];
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'From: ' . self::encodeAddress($fromName, $fromEmail);
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'Reply-To: <' . $replyTo . '>';
            }
            $headers[] = 'To: <' . $to . '>';
            $headers[] = 'Subject: ' . self::encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::messageIdHost($fromEmail, $ehloHost) . '>';
            $headers[] = 'X-Auto-Response-Suppress: OOF, AutoReply';
            $headers[] = 'Auto-Submitted: auto-generated';

            if ($hasInline) {
                $headers[] = 'Content-Type: multipart/related; type="multipart/alternative"; boundary="' . $relatedBoundary . '"';

                $body = '';
                $body .= '--' . $relatedBoundary . "\r\n";
                $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n\r\n";
                $body .= $altBody . "\r\n";

                foreach ($inlineImages as $img) {
                    $path = $img['path'] ?? '';
                    $cid = $img['cid'] ?? '';
                    if ($path === '' || $cid === '' || !is_file($path)) {
                        continue;
                    }
                    $bytes = file_get_contents($path);
                    if ($bytes === false) {
                        continue;
                    }
                    $mime = self::detectMime($path);
                    $name = $img['name'] ?? basename($path);

                    $body .= '--' . $relatedBoundary . "\r\n";
                    $body .= 'Content-Type: ' . $mime . '; name="' . self::encodeHeader($name) . '"' . "\r\n";
                    $body .= "Content-Transfer-Encoding: base64\r\n";
                    $body .= 'Content-ID: <' . $cid . '>' . "\r\n";
                    $body .= 'Content-Disposition: inline; filename="' . self::encodeHeader($name) . '"' . "\r\n\r\n";
                    $body .= chunk_split(base64_encode($bytes)) . "\r\n";
                }

                $body .= '--' . $relatedBoundary . "--\r\n";
            } else {
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
                $body = $altBody;
            }

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $data = preg_replace("/\r\n\./", "\r\n..", $data);
            fwrite($fp, $data . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::command($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    private static function ehloName(): string
    {
        $base = (string) Config::get('APP_BASE_URL', '');
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return 'email-broadcaster.local';
    }

    private static function messageIdHost(string $fromEmail, string $fallback): string
    {
        $at = strrpos($fromEmail, '@');
        if ($at !== false) {
            $domain = substr($fromEmail, $at + 1);
            if (is_string($domain) && $domain !== '') {
                return $domain;
            }
        }
        return $fallback !== '' ? $fallback : 'localhost';
    }

    private static function detectMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private static function encodeAddress(string $name, string $email): string
    {
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function command($fp, string $cmd, array $okCodes): void
    {
        fwrite($fp, $cmd . "\r\n");
        self::expect($fp, $okCodes);
    }

    private static function expect($fp, array $okCodes): string
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    }
}
