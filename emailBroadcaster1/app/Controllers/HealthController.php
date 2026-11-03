<?php

class HealthController
{
    public function show(): void
    {
        $driver = strtolower((string) Config::get('DB_DRIVER', 'sqlite'));
        $accounts = Config::smtpAccounts();
        $quota = new SmtpQuota();
        $snapshot = $quota->snapshot();

        $accountStatus = [];
        foreach ($accounts as $account) {
            $id = $account['id'];
            $accountStatus[] = [
                'id' => $id,
                'user' => $account['user'],
                'from' => $account['from_email'],
                'remaining' => $quota->remaining($id),
                'used' => (int) ($snapshot['counts'][$id] ?? 0),
            ];
        }

        json_response([
            'ok' => true,
            'smtpConfigured' => count($accounts) > 0,
            'smtpAccounts' => count($accounts),
            'smtpQuota' => [
                'perAccount' => $snapshot['limit'],
                'windowSeconds' => $snapshot['window_seconds'],
                'accounts' => $accountStatus,
            ],
            'dbDriver' => $driver,
            'dbHost' => $driver === 'mysql' ? (string) Config::get('DB_HOST', '') : null,
            'dbName' => $driver === 'mysql' ? (string) Config::get('DB_NAME', '') : null,
            'cron' => '*/10 * * * * (via cron/send_emails.php)',
            'batchSize' => (int) Config::get('EMAIL_BATCH_SIZE', 20),
            'singleAccountMax' => (int) Config::get('SMTP_SINGLE_ACCOUNT_MAX', 20),
        ]);
    }
}
