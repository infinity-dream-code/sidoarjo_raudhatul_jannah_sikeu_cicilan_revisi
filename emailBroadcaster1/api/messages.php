<?php

declare(strict_types=1);

try {
    $app = require __DIR__ . '/_init.php';
    $app['messages']->index();
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Internal error'], 500);
}
