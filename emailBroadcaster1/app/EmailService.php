<?php

require_once __DIR__ . '/Templates/billing.php';
require_once __DIR__ . '/Templates/payment_confirmation.php';
require_once __DIR__ . '/Templates/bill_notice.php';

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

        $plan = $this->buildPriorityPlan($slots);
        $pending = $plan['messages'];
        $paymentSlotIndexes = $plan['paymentSlotIndexes'];
        $otherSlotIndexes = $plan['otherSlotIndexes'];

        $sent = 0;
        $failed = 0;
        $delayUs = max(0, (int) Config::get('EMAIL_SEND_DELAY_US', 200000));

        foreach ($pending as $row) {
            $type = strtoupper((string) ($row['type'] ?? ''));
            $picked = null;

            if ($type === 'PAYMENT_CONFIRMATION') {
                $picked = $this->pickSlot($slots, $paymentSlotIndexes);
            }
            if ($picked === null) {
                $picked = $this->pickSlot($slots, $otherSlotIndexes);
            }
            if ($picked === null) {
                $picked = $this->pickSlot($slots, array_keys($slots));
            }
            if ($picked === null) {
                break;
            }

            $account = $slots[$picked]['account'];
            $slots[$picked]['remaining']--;

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
     * Prioritas:
     * 1. BILLING
     * 2. PAYMENT_CONFIRMATION — cadangan 1 akun SMTP saat tagihan sibuk
     * 3. BILL_UPDATE — setelah tagihan selesai
     * 4. EXP_UPDATE — setelah #3
     *
     * @param list<array{account:array,remaining:int}> $slots
     * @return array{messages:list<array>,paymentSlotIndexes:list<int>,otherSlotIndexes:list<int>}
     */
    private function buildPriorityPlan(array $slots): array
    {
        $slotCount = count($slots);
        $allIndexes = range(0, $slotCount - 1);

        $billingPending = $this->repo->countPendingByType('BILLING');
        $paymentPending = $this->repo->countPendingByType('PAYMENT_CONFIRMATION');

        $reserveAccounts = max(0, (int) Config::get('PAYMENT_RESERVE_ACCOUNTS', 1));
        $reserveSlotsSingle = max(0, (int) Config::get('PAYMENT_RESERVE_SLOTS', 1));

        $paymentSlotIndexes = $allIndexes;
        $otherSlotIndexes = $allIndexes;
        $paymentCap = 0;
        $otherCap = 0;

        foreach ($slots as $slot) {
            $otherCap += $slot['remaining'];
        }

        // Tagihan sibuk + ada bayar → cadangkan 1 akun (atau sedikit slot jika cuma 1 akun).
        if ($billingPending > 0 && $paymentPending > 0 && $reserveAccounts > 0) {
            if ($slotCount >= 2) {
                $payAcc = min($reserveAccounts, $slotCount - 1);
                $paymentSlotIndexes = array_slice($allIndexes, -$payAcc);
                $otherSlotIndexes = array_slice($allIndexes, 0, $slotCount - $payAcc);

                $paymentCap = 0;
                foreach ($paymentSlotIndexes as $idx) {
                    $paymentCap += $slots[$idx]['remaining'];
                }
                $otherCap = 0;
                foreach ($otherSlotIndexes as $idx) {
                    $otherCap += $slots[$idx]['remaining'];
                }
            } else {
                $paymentCap = min($reserveSlotsSingle, $slots[0]['remaining'], $paymentPending);
                $otherCap = max(0, $slots[0]['remaining'] - $paymentCap);
                $paymentSlotIndexes = [0];
                $otherSlotIndexes = [0];
            }
        } elseif ($paymentPending > 0 && $billingPending <= 0) {
            // Tagihan selesai: semua kapasitas boleh untuk bayar dulu, lalu #3/#4.
            $paymentCap = $otherCap;
            $otherCap = $otherCap; // sisa setelah payment diisi dipakai notice
        } else {
            $paymentCap = 0;
        }

        $messages = [];
        $taken = [];

        $take = function (string $type, int $limit) use (&$messages, &$taken): int {
            if ($limit <= 0) {
                return 0;
            }
            $rows = $this->repo->findPendingByType($type, $limit);
            $added = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '' && isset($taken[$id])) {
                    continue;
                }
                $messages[] = $row;
                if ($id !== '') {
                    $taken[$id] = true;
                }
                $added++;
            }

            return $added;
        };

        // Isi cadangan bayar.
        $payTaken = $take('PAYMENT_CONFIRMATION', min($paymentCap, $paymentPending));

        if ($billingPending > 0) {
            // Prioritas 1: tagihan memakai kapasitas non-reserve.
            $take('BILLING', $otherCap);
        } else {
            // Setelah tagihan habis: sisa kapasitas setelah bayar → #3 lalu #4.
            $left = max(0, $otherCap - $payTaken);
            if ($paymentPending > $payTaken) {
                $morePay = $take('PAYMENT_CONFIRMATION', $left);
                $left -= $morePay;
            }
            if ($left > 0) {
                $left -= $take('BILL_UPDATE', $left);
            }
            if ($left > 0) {
                $take('EXP_UPDATE', $left);
            }
        }

        return [
            'messages' => $messages,
            'paymentSlotIndexes' => $paymentSlotIndexes,
            'otherSlotIndexes' => $otherSlotIndexes,
        ];
    }

    /**
     * @param list<array{account:array,remaining:int}> $slots
     * @param list<int> $indexes
     */
    private function pickSlot(array $slots, array $indexes): ?int
    {
        foreach ($indexes as $idx) {
            if (isset($slots[$idx]) && $slots[$idx]['remaining'] > 0) {
                return (int) $idx;
            }
        }

        return null;
    }

    /**
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
        $type = strtoupper((string) ($message['type'] ?? ''));
        if ($type === 'PAYMENT_CONFIRMATION') {
            return render_payment_confirmation_email($message);
        }
        if ($type === 'BILL_UPDATE' || $type === 'EXP_UPDATE') {
            return render_bill_notice_email($message);
        }

        return render_billing_email($message);
    }
}
