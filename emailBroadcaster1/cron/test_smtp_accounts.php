<?php

/**
 * Tes kirim 1 email per akun SMTP ke satu penerima (tanpa DB).
 * Usage:
 *   php cron/test_smtp_accounts.php
 *   php cron/test_smtp_accounts.php ramasat773@gmail.com
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/helpers.php';
require_once $ROOT . '/app/Config.php';
require_once $ROOT . '/app/Mailer.php';

Config::load($ROOT);

$to = $argv[1] ?? 'ramasat773@gmail.com';
$accounts = Config::smtpAccounts();

if ($accounts === []) {
    echo "Tidak ada akun SMTP terkonfigurasi.\n";
    exit(1);
}

echo "Penerima: {$to}\n";
echo 'Akun: ' . count($accounts) . "\n\n";

$ok = 0;
$fail = 0;

foreach ($accounts as $i => $account) {
    $n = $i + 1;
    $from = $account['from_email'] ?? $account['user'];
    $subject = "Tes SMTP #{$n} - " . ($account['user'] ?? 'unknown');
    $html = '<p>Ini email tes dari akun <strong>' . htmlspecialchars((string) $from, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p>Waktu: ' . date('Y-m-d H:i:s') . '</p>';
    $text = "Ini email tes dari akun {$from}\nWaktu: " . date('Y-m-d H:i:s');

    echo "[{$n}] {$account['user']} ... ";
    try {
        Mailer::send($to, $subject, $html, $text, [], $account);
        echo "OK\n";
        $ok++;
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $fail++;
    }

    usleep(300000);
}

echo "\nSelesai: sent={$ok} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);
