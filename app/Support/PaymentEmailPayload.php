<?php

namespace App\Support;

use App\Models\MetodeBayar;
use App\Models\PaymentEmailLog;
use App\Models\scctbill;
use App\Models\scctcust;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentEmailPayload
{
    public static function enabled(): bool
    {
        return (bool) config('email_broadcaster.payment_email_enabled', true);
    }

    /**
     * Build payload konfirmasi pembayaran untuk 1 tagihan.
     * null = dilewati (email ortu kosong/invalid, belum ada kenaikan BILLPAID, atau data tidak ditemukan).
     *
     * @return array{payload:array<string,mixed>,billpaid_snapshot:int,payment_amount:int,aa:string}|null
     */
    public static function forBillIncrease(string $billAa, ?int $expectedBillPaid = null): ?array
    {
        $aa = trim($billAa);
        if ($aa === '') {
            return null;
        }

        $bill = scctbill::query()->where('AA', $aa)->first();
        if (!$bill) {
            return null;
        }

        $currentPaid = (int) ($bill->BILLPAID ?? 0);
        if ($expectedBillPaid !== null) {
            $currentPaid = max($currentPaid, $expectedBillPaid);
        }

        if ($currentPaid <= 0) {
            return null;
        }

        $lastSnapshot = PaymentEmailLog::lastSnapshot($aa);
        if ($currentPaid <= $lastSnapshot) {
            return null;
        }

        $paymentAmount = $currentPaid - $lastSnapshot;
        if ($paymentAmount <= 0) {
            return null;
        }

        $siswa = scctcust::query()->where('CUSTID', $bill->CUSTID)->first();
        if (!$siswa) {
            return null;
        }

        $email = trim((string) ($siswa->EMAIL_ORTU ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $nis = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-')
            ? (string) $siswa->NOCUST
            : (string) ($siswa->nocust ?? $siswa->NUM2ND ?? '');
        $nama = (string) ($siswa->NMCUST ?? $siswa->nmcust ?? '-');
        $kelasLabel = trim((string) ($siswa->DESC02 ?? '') . ' ' . (string) ($siswa->DESC03 ?? ''));

        $isInstallable = ((int) ($bill->isINSTALLABLE ?? 0) === 1) ? 1 : 0;
        $noVa = scctcust::showVA($nis, $isInstallable);

        $itemName = trim((string) ($bill->BILLNM ?? 'Tagihan'));
        $periode = trim((string) ($bill->BILLAC ?? $bill->BTA ?? ''));
        if ($periode !== '') {
            $itemName .= ' (' . $periode . ')';
        }

        $billAmount = (int) ($bill->BILLAM ?? 0);
        $outstanding = (int) ($bill->PAYMENTLEFT ?? max(0, $billAmount - $currentPaid));

        $paidAt = (string) ($bill->PAIDDT_ACTUAL ?? $bill->PAIDDT ?? now()->toDateTimeString());
        $paymentMethod = self::resolvePaymentMethodLabel(
            $bill->FIDBANK !== null ? (string) $bill->FIDBANK : null,
            $bill->NOREFF !== null ? (string) $bill->NOREFF : null
        );

        $orderNumber = trim((string) ($bill->BILLCD ?? $aa));
        $subject = 'Konfirmasi pembayaran ananda ' . $nama;
        if ($itemName !== '') {
            $subject .= ' - ' . $itemName;
        }

        $payload = [
            'method' => 'payment_confirmation',
            'nama' => $nama,
            'email' => $email,
            'nis' => $nis !== '' ? $nis : null,
            'kelas' => $kelasLabel !== '' ? $kelasLabel : null,
            'va' => $noVa !== '' ? $noVa : null,
            'orderNumber' => $orderNumber !== '' ? $orderNumber : $aa,
            'paymentAmount' => $paymentAmount,
            'billAmount' => $billAmount,
            'outstandingAmount' => $outstanding,
            'referenceNumber' => trim((string) ($bill->TRANSNO ?? $bill->NOREFF ?? '')) ?: null,
            'paymentMethod' => $paymentMethod,
            'paidAt' => $paidAt,
            'subject' => $subject,
            'organizationName' => (string) (config('app.nama_instansi') ?: 'Raudlatul Jannah Sidoarjo'),
            'items' => [[
                'name' => $itemName,
                'amount' => $billAmount > 0 ? $billAmount : $paymentAmount,
            ]],
        ];

        return [
            'payload' => $payload,
            'billpaid_snapshot' => $currentPaid,
            'payment_amount' => $paymentAmount,
            'aa' => $aa,
        ];
    }

    public static function resolvePaymentMethodLabel(?string $fidBank, ?string $noreff): string
    {
        $displayFid = MetodeBayarHelper::resolveDisplayFidBank($fidBank, $noreff);
        if ($displayFid === MetodeBayarHelper::ANDROID_FIDBANK) {
            return 'ANDROID';
        }

        $noreffTrim = trim((string) $noreff);
        if (strcasecmp($noreffTrim, 'TELLER') === 0 || strcasecmp($noreffTrim, 'MNL') === 0) {
            $map = MetodeBayar::attributes();
            if ($fidBank !== null && isset($map[$fidBank])) {
                return $map[$fidBank];
            }

            return 'Manual Teller';
        }

        $map = MetodeBayar::attributes();
        if ($displayFid !== '' && isset($map[$displayFid])) {
            return $map[$displayFid];
        }
        if ($fidBank !== null && isset($map[$fidBank])) {
            return $map[$fidBank];
        }

        if ($fidBank !== null && $fidBank !== '') {
            return $fidBank;
        }

        return $noreffTrim !== '' ? $noreffTrim : 'Pembayaran';
    }

    /**
     * Antri email untuk 1 tagihan jika BILLPAID naik.
     *
     * @return array{queued:bool,skipped:bool,reason?:string}
     */
    public static function dispatchForBill(string $billAa, ?int $expectedBillPaid = null): array
    {
        if (!self::enabled()) {
            return ['queued' => false, 'skipped' => true, 'reason' => 'disabled'];
        }

        $built = self::forBillIncrease($billAa, $expectedBillPaid);
        if ($built === null) {
            return ['queued' => false, 'skipped' => true, 'reason' => 'no_delta_or_no_email'];
        }

        try {
            $result = EmailBroadcaster::enqueuePaymentConfirmation([$built['payload']]);
        } catch (Throwable $e) {
            Log::warning('payment email enqueue gagal', [
                'aa' => $built['aa'],
                'error' => $e->getMessage(),
            ]);

            return ['queued' => false, 'skipped' => true, 'reason' => 'enqueue_failed'];
        }

        $queued = (int) ($result['queued'] ?? 0);
        if ($queued < 1) {
            return ['queued' => false, 'skipped' => true, 'reason' => 'broadcaster_failed'];
        }

        $broadcasterId = null;
        $first = $result['data'][0] ?? null;
        if (is_array($first)) {
            $broadcasterId = isset($first['id']) ? (string) $first['id'] : null;
        }

        try {
            PaymentEmailLog::query()->updateOrCreate(
                [
                    'aa' => $built['aa'],
                    'billpaid_snapshot' => $built['billpaid_snapshot'],
                ],
                [
                    'payment_amount' => $built['payment_amount'],
                    'queued_at' => now(),
                    'broadcaster_id' => $broadcasterId,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('payment email log gagal disimpan', [
                'aa' => $built['aa'],
                'error' => $e->getMessage(),
            ]);
        }

        return ['queued' => true, 'skipped' => false];
    }

    /**
     * @param  array<int, string|int>  $billAas
     */
    public static function dispatchMany(array $billAas): array
    {
        $queued = 0;
        $skipped = 0;

        foreach ($billAas as $aa) {
            $result = self::dispatchForBill((string) $aa);
            if (!empty($result['queued'])) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Antrikan setelah response HTTP selesai (manual pembayaran).
     *
     * @param  array<int, string|int>  $billAas
     */
    public static function dispatchAfterResponse(array $billAas): void
    {
        if (!self::enabled()) {
            return;
        }

        $billAas = array_values(array_unique(array_filter(array_map(
            static fn ($aa) => trim((string) $aa),
            $billAas
        ), static fn ($aa) => $aa !== '')));

        if ($billAas === []) {
            return;
        }

        dispatch(function () use ($billAas) {
            try {
                $result = self::dispatchMany($billAas);
                $queued = (int) ($result['queued'] ?? 0);
                $immediateMax = max(0, (int) config('email_broadcaster.immediate_max', 3));

                if ($queued > 0 && $queued <= $immediateMax) {
                    EmailBroadcaster::processNow();
                }
            } catch (Throwable $e) {
                Log::warning('payment email afterResponse gagal', [
                    'count' => count($billAas),
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }
}
