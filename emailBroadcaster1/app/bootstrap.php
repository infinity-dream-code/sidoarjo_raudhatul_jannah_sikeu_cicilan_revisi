<?php

declare(strict_types=1);

$ROOT = dirname(__DIR__);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/SmtpQuota.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/MessageRepository.php';
require_once __DIR__ . '/Controllers/BillingController.php';
require_once __DIR__ . '/Controllers/PaymentController.php';
require_once __DIR__ . '/Controllers/BillNoticeController.php';
require_once __DIR__ . '/Controllers/NotifyController.php';
require_once __DIR__ . '/Controllers/MessageController.php';
require_once __DIR__ . '/Controllers/HealthController.php';

Config::load($ROOT);
$pdo = Database::pdo($ROOT);

return [
    'root' => $ROOT,
    'pdo' => $pdo,
];
