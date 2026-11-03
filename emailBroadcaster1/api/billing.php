<?php

declare(strict_types=1);

try {
    $app = require __DIR__ . '/_init.php';
    // Hormati field method di payload (mis. payment_confirmation).
    // Jika method kosong, default = billing.
    $notify = new NotifyController($app['billing'], $app['payment']);
    $notify->store('billing');
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Internal error'], 500);
}
