<?php

declare(strict_types=1);

try {
    $app = require __DIR__ . '/api/_init.php';
    $app['health']->show();
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Internal error'], 500);
}
