<?php

declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === '_init.php') {
    http_response_code(403);
    exit('Forbidden');
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$repo = new MessageRepository($app['pdo']);
$emailService = new EmailService($repo);

return [
    'root' => $app['root'],
    'pdo' => $app['pdo'],
    'repo' => $repo,
    'emailService' => $emailService,
    'billing' => new BillingController($repo),
    'payment' => new PaymentController($repo),
    'messages' => new MessageController($repo, $emailService),
    'health' => new HealthController(),
];
