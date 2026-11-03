<?php

// Jalankan tiap 10 menit via crontab (SSH):
//   which php
//   crontab -e
// Lalu tempel baris ini (satu baris):
//   */10 * * * * /usr/bin/php /var/www/html/emailBroadcaster/cron/send_emails.php >> /var/www/html/emailBroadcaster/storage/cron.log 2>&1
// Cek: crontab -l
//
// Kuota: max 20 pesan / akun SMTP / 10 menit.
// Pending ≤20 → 1 akun; pending >20 → sampai 3 akun (≈60 pesan / 10 menit).

declare(strict_types=1);

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$repo = new MessageRepository($app['pdo']);
$service = new EmailService($repo);
$result = $service->processPending();

$ts = date('Y-m-d H:i:s');
if (!empty($result['skipped'])) {
    echo "[{$ts}] skip: {$result['reason']}\n";
    exit(0);
}

if (($result['processed'] ?? 0) > 0 || ($result['sent'] ?? 0) > 0 || ($result['failed'] ?? 0) > 0) {
    $accounts = isset($result['accountsUsed']) ? implode(',', $result['accountsUsed']) : '-';
    echo "[{$ts}] processed={$result['processed']} sent={$result['sent']} failed={$result['failed']} accounts={$accounts}\n";
} elseif (!empty($result['reason'])) {
    echo "[{$ts}] idle: {$result['reason']}\n";
} else {
    echo "[{$ts}] idle\n";
}
