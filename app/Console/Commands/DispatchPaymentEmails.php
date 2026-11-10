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
                            {--baseline : Catat snapshot BILLPAID tanpa kirim email (untuk go-live)}';

    protected $description = 'Antri email konfirmasi pembayaran untuk tagihan yang BILLPAID naik (manual/bank/Android)';

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
            $result = PaymentEmailPayload::dispatchForBill((string) $bill->AA, (int) $bill->BILLPAID);
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
     * Ambil kandidat dari DATA_MYSQL, bandingkan snapshot di DB default (tanpa cross-DB JOIN).
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
                });
            })
            ->orderByDesc('PAIDDT_ACTUAL')
            ->orderByDesc('PAIDDT')
            ->limit(max($limit * 5, 100))
            ->get(['AA', 'BILLPAID']);

        if ($bills->isEmpty()) {
            return collect();
        }

        $aas = $bills->pluck('AA')->map(fn ($aa) => (string) $aa)->all();
        $snapshots = PaymentEmailLog::query()
            ->whereIn('aa', $aas)
            ->selectRaw('aa, MAX(billpaid_snapshot) as max_paid')
            ->groupBy('aa')
            ->pluck('max_paid', 'aa');

        return $bills
            ->filter(function ($bill) use ($snapshots) {
                $aa = (string) $bill->AA;
                $current = (int) $bill->BILLPAID;
                $last = (int) ($snapshots[$aa] ?? 0);

                return $current > $last;
            })
            ->take($limit)
            ->values();
    }
}
