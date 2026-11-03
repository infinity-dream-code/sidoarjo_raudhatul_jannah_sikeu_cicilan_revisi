<?php

declare(strict_types=1);

try {
    $app = require __DIR__ . '/_init.php';
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '') {
        json_response(['ok' => false, 'error' => 'Parameter id wajib'], 400);
    }
    $app['messages']->retry($id);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Internal error'], 500);
}
