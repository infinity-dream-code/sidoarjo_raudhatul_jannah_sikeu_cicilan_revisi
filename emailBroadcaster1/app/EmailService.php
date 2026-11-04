<?php

require_once __DIR__ . '/Templates/billing.php';
require_once __DIR__ . '/Templates/payment_confirmation.php';

class EmailService
{
    public function __construct(private MessageRepository $repo)
    {
    }

    public function processPending(?int $limit = null): array
    {
        $accounts = Config::smtpAccounts();
        if ($accounts === []) {
            return [
                'skipped' => true,
                'reason' => 'SMTP not configured',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $quota = new SmtpQuota();
        $pendingTotal = $this->repo->countPending();
        $activeAccounts = $this->selectActiveAccounts($accounts, $pendingTotal);
        if ($activeAccounts === []) {
            return [
                'skipped' => true,
                'reason' => 'No SMTP account available',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $perAccountCap = $limit ?? (int) Config::get('EMAIL_BATCH_SIZE', Config::smtpQuotaPerAccount());
        $perAccountCap = max(1, $perAccountCap);

        $slots = [];
        foreach ($activeAccounts as $account) {
            $remaining = min($perAccountCap, $quota->remaining($account['id']));
            if ($remaining > 0) {
                $slots[] = [
                    'account' => $account,
                    'remaining' => $remaining,
                ];
            }
        }

        if ($slots === []) {
            return [
                'skipped' => false,
                'reason' => 'SMTP quota exhausted for current window',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'pending' => $pendingTotal,
                'accountsUsed' => array_column($activeAccounts, 'id'),
                'quota' => $quota->snapshot(),
            ];
        }

        $fetchLimit = 0;
        foreach ($slots as $slot) {
            $fetchLimit += $slot['remaining'];
        }

        $pending = $this->repo->findPending($fetchLimit);
        $sent = 0;
        $failed = 0;
        $slotIndex = 0;
        $slotCount = count($slots);
        $delayUs = max(0, (int) Config::get('EMAIL_SEND_DELAY_US', 200000));

        foreach ($pending as $row) {
            $picked = null;
            for ($try = 0; $try < $slotCount; $try++) {
                $idx = ($slotIndex + $try) % $slotCount;
                if ($slots[$idx]['remaining'] > 0) {
                    $picked = $idx;
                    break;
                }
            }
            if ($picked === null) {
                break;
            }

            $account = $slots[$picked]['account'];
            $slots[$picked]['remaining']--;
            $slotIndex = ($picked + 1) % $slotCount;

            $this->repo->markSending($row['id']);
            $message = message_to_array($row);

            try {
                $rendered = $this->render($message);
                Mailer::send(
                    $message['recipientEmail'],
                    $message['subject'],
                    $rendered['html'],
                    $rendered['text'],
                    $rendered['inline'] ?? [],
                    $account
                );
                $this->repo->markSent($row['id']);
                $quota->record($account['id'], 1);
                $sent++;
            } catch (Throwable $e) {
                $this->repo->markFailed($row['id'], $e->getMessage());
                $failed++;
            }

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return [
            'skipped' => false,
            'processed' => count($pending),
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pendingTotal,
            'accountsUsed' => array_column($activeAccounts, 'id'),
            'quota' => $quota->snapshot(),
        ];
    }

    /**
     * Aturan akun berdasarkan antrian PENDING:
     * - ≤20  → 1 akun (SMTP1)
     * - ≤40  → 2 akun
     * - >40  → sampai 3 akun
     * Kuota tiap akun: 20 pesan / 10 menit (FIFO).
     *
     * @param list<array{id:string,host:string,port:int,secure:string,user:string,pass:string,from_email:string,from_name:string}> $accounts
     * @return list<array{id:string,host:string,port:int,secure:string,user:string,pass:string,from_email:string,from_name:string}>
     */
    private function selectActiveAccounts(array $accounts, int $pendingTotal): array
    {
        if ($accounts === []) {
            return [];
        }

        $oneMax = (int) Config::get('SMTP_SINGLE_ACCOUNT_MAX', 20);
        $twoMax = (int) Config::get('SMTP_TWO_ACCOUNT_MAX', 40);

        if ($pendingTotal <= $oneMax) {
            $need = 1;
        } elseif ($pendingTotal <= $twoMax) {
            $need = 2;
        } else {
            $need = 3;
        }

        return array_slice($accounts, 0, min($need, count($accounts)));
    }

    private function render(array $message): array
    {
        if (($message['type'] ?? '') === 'PAYMENT_CONFIRMATION') {
            return render_payment_confirmation_email($message);
        }
        return render_billing_email($message);
    }
}
