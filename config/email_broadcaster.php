<?php

return [
    'url' => rtrim((string) env('EMAIL_BROADCASTER_URL', 'http://101.50.2.56/emailBroadcaster'), '/'),
    'token' => (string) env('EMAIL_BROADCASTER_TOKEN', ''),
    'timeout' => (int) env('EMAIL_BROADCASTER_TIMEOUT', 30),
    // ≤ N pesan: langsung process-now setelah antri (tidak tunggu cron).
    'immediate_max' => (int) env('EMAIL_BROADCASTER_IMMEDIATE_MAX', 3),
];
