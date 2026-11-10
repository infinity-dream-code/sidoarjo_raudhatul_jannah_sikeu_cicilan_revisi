<?php

return [
    'url' => rtrim((string) env('EMAIL_BROADCASTER_URL', 'http://101.50.2.56/emailBroadcaster'), '/'),
    'token' => (string) env('EMAIL_BROADCASTER_TOKEN', ''),
    'timeout' => (int) env('EMAIL_BROADCASTER_TIMEOUT', 30),
    // ≤ N pesan: langsung process-now setelah antri (tidak tunggu cron).
    'immediate_max' => (int) env('EMAIL_BROADCASTER_IMMEDIATE_MAX', 3),

    // Notif email konfirmasi pembayaran (manual + bank VA + Android via cron).
    'payment_email_enabled' => filter_var(
        env('EMAIL_PAYMENT_CONFIRMATION_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN
    ),
    // Scan scctbill yang PAIDDT / PAIDDT_ACTUAL dalam N hari terakhir.
    'payment_lookback_days' => max(1, (int) env('EMAIL_PAYMENT_LOOKBACK_DAYS', 7)),
];
