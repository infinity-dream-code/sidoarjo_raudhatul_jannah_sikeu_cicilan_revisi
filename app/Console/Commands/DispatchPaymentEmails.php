<?php

namespace App\Console\Commands;

use App\Models\PaymentEmailLog;
use App\Models\scctbill;
use App\Support\EmailBroadcaster;
use App\Support\PaymentEmailPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchPaymentEmails extends Command
{
    protected $signature = 'payment-email:dispatch
                            {--limit=100 : Maksimal tagihan diproses per run}
                            {--days= : Override lookback days}
                            {--baseline : Catat snapshot BILLPAID/INSTALLMENT tanpa kirim email (untuk go-live)}';

    protected $description = 'Antri email konfirmasi pembayaran (Close via BILLPAID; Open/cicil via INSTALLMENT 1,2,…)';

    public function handle(): int
    {
        if (!PaymentEmailPayload::enabled() && !$this->option('baseline')) {
            $this->info('Payment email disabled (EMAIL_PAYMENT_CONFIRMATION_ENABLED=false).');

            return self::SUCCESS;
        }

        $lookbackDays = (int) ($this->option('days') ?: config('email_broadcaster.payment_lookback_days', 7));
        $lookbackDays = max(1, $lookbackDays);
        $limit = max(1, (int) $this->option('limit'));
        $since = Carbon::now()->subDays($lookbackDays)->startOfDay();
        $baseline = (bool) $this->option('baseline');

        $candidates = $this->findCandidates($since, $limit);
        if ($candidates->isEmpty()) {
            $this->info('Tidak ada pembayaran baru untuk diemail.');

            return self::SUCCESS;
        }

        if ($baseline) {
            $marked = 0;
            foreach ($candidates as $bill) {
                PaymentEmailLog::query()->updateOrCreate(
                    [
                        'aa' => (string) $bill->AA,
                        'billpaid_snapshot' => (int) $bill->BILLPAID,
                    ],
                    [
                        'installment_snapshot' => (int) ($bill->INSTALLMENT ?? 0),
                        'payment_amount' => 0,
                        'queued_at' => now(),
                        'broadcaster_id' => 'baseline',
                    ]
                );
                $marked++;
            }
            $this->info("Baseline recorded for {$marked} bills (no email sent).");

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($candidates as $bill) {
            $result = PaymentEmailPayload::dispatchForBill(
                (string) $bill->AA,
                (int) $bill->BILLPAID,
                (int) ($bill->INSTALLMENT ?? 0)
            );
            if (!empty($result['queued'])) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        $immediateMax = max(0, (int) config('email_broadcaster.immediate_max', 3));
        if ($queued > 0 && $queued <= $immediateMax) {
            try {
                EmailBroadcaster::processNow();
            } catch (Throwable $e) {
                Log::warning('payment-email:dispatch processNow gagal', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Queued={$queued}, skipped={$skipped}, scanned={$candidates->count()}");

        return self::SUCCESS;
    }

    /**
     * Kandidat:
     * - Close/umum: BILLPAID naik + PAIDDT dalam lookback
     * - Cicilan (isINSTALLABLE=1): INSTALLMENT >= 1 dan naik dibanding log
     *
     * @return Collection<int, scctbill>
     */
    private function findCandidates(Carbon $since, int $limit): Collection
    {
        $bills = scctbill::query()
            ->where('BILLPAID', '>', 0)
            ->where(function ($q) use ($since) {
                $q->where(function ($inner) use ($since) {
                    $inner->whereNotNull('PAIDDT_ACTUAL')
                        ->where('PAIDDT_ACTUAL', '>=', $since);
                })->orWhere(function ($inner) use ($since) {
                    $inner->whereNull('PAIDDT_ACTUAL')
                        ->whereNotNull('PAIDDT')
                        ->where('PAIDDT', '>=', $since);
                })->orWhere(function ($inner) {
                    // Cicilan: INSTALLMENT 1,2,... = sudah bayar (kadang PAIDDT kosong).
                    $inner->where('isINSTALLABLE', 1)
                        ->where('INSTALLMENT', '>', 0);
                });
            })
            ->orderByDesc('PAIDDT_ACTUAL')
            ->orderByDesc('PAIDDT')
            ->orderByDesc('INSTALLMENT')
            ->limit(max($limit * 5, 150))
            ->get(['AA', 'BILLPAID', 'INSTALLMENT', 'isINSTALLABLE']);

        if ($bills->isEmpty()) {
            return collect();
        }

        $aas = $bills->pluck('AA')->map(fn ($aa) => (string) $aa)->all();
        $logs = PaymentEmailLog::query()
            ->whereIn('aa', $aas)
            ->get(['aa', 'billpaid_snapshot', 'installment_snapshot'])
            ->groupBy('aa');

        return $bills
            ->filter(function ($bill) use ($logs) {
                $aa = (string) $bill->AA;
                $group = $logs->get($aa);
                $lastPaid = $group ? (int) $group->max('billpaid_snapshot') : 0;
                $lastInst = $group ? (int) $group->max('installment_snapshot') : 0;

                $currentPaid = (int) $bill->BILLPAID;
                $currentInst = (int) ($bill->INSTALLMENT ?? 0);
                $isInstallable = ((int) ($bill->isINSTALLABLE ?? 0) === 1);

                if ($currentPaid > $lastPaid) {
                    return true;
                }

                return $isInstallable && $currentInst > 0 && $currentInst > $lastInst;
            })
            ->take($limit)
            ->values();
    }
}
