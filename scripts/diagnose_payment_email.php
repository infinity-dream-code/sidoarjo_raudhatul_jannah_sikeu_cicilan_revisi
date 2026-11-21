<?php

/**
 * Diagnosa kenapa payment email tidak ke-queue (TIDAK mengirim email).
 * Usage: php scripts/diagnose_payment_email.php [NIS|AA]
 */

use App\Models\PaymentEmailLog;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Support\PaymentEmailPayload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$arg = trim((string) ($argv[1] ?? '1234567006'));
$lookbackDays = max(1, (int) config('email_broadcaster.payment_lookback_days', 7));
$since = Carbon::now()->subDays($lookbackDays)->startOfDay();

echo "enabled=" . (PaymentEmailPayload::enabled() ? '1' : '0') . "\n";
echo "lookback_days={$lookbackDays} since={$since}\n";
echo "broadcaster=" . config('email_broadcaster.url') . "\n";
echo "immediate_max=" . config('email_broadcaster.immediate_max') . "\n\n";

$siswa = null;
$bills = collect();

if (ctype_digit($arg) && strlen($arg) >= 6) {
    $siswa = scctcust::query()->where('NOCUST', $arg)->orWhere('NUM2ND', $arg)->first();
    if (!$siswa) {
        fwrite(STDERR, "Siswa NIS {$arg} tidak ditemukan\n");
        exit(1);
    }
    $bills = scctbill::query()
        ->where('CUSTID', $siswa->CUSTID)
        ->where('BILLPAID', '>', 0)
        ->orderByDesc('PAIDDT_ACTUAL')
        ->orderByDesc('PAIDDT')
        ->limit(15)
        ->get();
    echo "SISWA CUSTID={$siswa->CUSTID} nama={$siswa->NMCUST} EMAIL_ORTU=" . trim((string) ($siswa->EMAIL_ORTU ?? '')) . "\n\n";
} else {
    $bill = scctbill::query()->where('AA', $arg)->first();
    if (!$bill) {
        fwrite(STDERR, "Bill AA {$arg} tidak ditemukan\n");
        exit(1);
    }
    $bills = collect([$bill]);
    $siswa = scctcust::query()->where('CUSTID', $bill->CUSTID)->first();
    if ($siswa) {
        echo "SISWA CUSTID={$siswa->CUSTID} nama={$siswa->NMCUST} EMAIL_ORTU=" . trim((string) ($siswa->EMAIL_ORTU ?? '')) . "\n\n";
    }
}

foreach ($bills as $bill) {
    $aa = (string) $bill->AA;
    $paid = (int) ($bill->BILLPAID ?? 0);
    $left = (int) ($bill->PAYMENTLEFT ?? 0);
    $inst = (int) ($bill->INSTALLMENT ?? 0);
    $installable = (int) ($bill->isINSTALLABLE ?? 0) === 1;
    $paidDt = (string) ($bill->PAIDDT ?? '');
    $paidDtAct = (string) ($bill->PAIDDT_ACTUAL ?? '');

    $inWindow = false;
    $windowReason = [];
    if ($paidDtAct !== '' && $paidDtAct !== '0000-00-00' && !str_starts_with($paidDtAct, '0000-00-00')) {
        try {
            $inWindow = Carbon::parse($paidDtAct)->gte($since);
            $windowReason[] = 'PAIDDT_ACTUAL ' . ($inWindow ? 'OK' : 'di luar lookback');
        } catch (Throwable) {
            $windowReason[] = 'PAIDDT_ACTUAL parse gagal';
        }
    } elseif ($paidDt !== '' && $paidDt !== '0000-00-00' && !str_starts_with($paidDt, '0000-00-00')) {
        try {
            $inWindow = Carbon::parse($paidDt)->gte($since);
            $windowReason[] = 'PAIDDT ' . ($inWindow ? 'OK' : 'di luar lookback');
        } catch (Throwable) {
            $windowReason[] = 'PAIDDT parse gagal';
        }
    }
    if ($installable && $inst > 0) {
        $inWindow = true;
        $windowReason[] = 'cicilan INSTALLMENT>0 (bypass tanggal)';
    }
    if (!$inWindow && $windowReason === []) {
        $windowReason[] = 'tidak ada PAIDDT/PAIDDT_ACTUAL valid dan bukan cicilan aktif';
    }

    $last = PaymentEmailLog::lastState($aa);
    $deltaPaid = $paid > $last['billpaid'];
    $deltaInst = $installable && $inst > 0 && $inst > $last['installment'];

    $emailOk = false;
    $email = '';
    if ($siswa) {
        $email = trim((string) ($siswa->EMAIL_ORTU ?? ''));
        $emailOk = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    echo "---- AA={$aa} BILLNM={$bill->BILLNM} ----\n";
    echo "BILLPAID={$paid} PAYMENTLEFT={$left} INSTALLMENT={$inst} PAIDST={$bill->PAIDST} isINSTALLABLE=" . ($installable ? 1 : 0) . "\n";
    echo "PAIDDT={$paidDt} PAIDDT_ACTUAL={$paidDtAct}\n";
    echo "FIDBANK={$bill->FIDBANK} NOREFF={$bill->NOREFF} TRANSNO={$bill->TRANSNO}\n";
    echo "scan_window=" . ($inWindow ? 'YES' : 'NO') . ' (' . implode('; ', $windowReason) . ")\n";
    echo "log_last billpaid={$last['billpaid']} installment={$last['installment']}\n";
    echo "delta_billpaid=" . ($deltaPaid ? 'YES' : 'NO') . " delta_installment=" . ($deltaInst ? 'YES' : 'NO') . "\n";
    echo "email_ortu=" . ($emailOk ? "OK ({$email})" : "INVALID/EMPTY ({$email})") . "\n";

    if (!$inWindow) {
        echo "KESIMPULAN: tidak masuk kandidat scan (tanggal/cicilan)\n\n";
        continue;
    }
    if (!$deltaPaid && !$deltaInst) {
        echo "KESIMPULAN: SKIP — sudah tercatat di payment_email_logs (tidak ada kenaikan)\n\n";
        continue;
    }
    if (!$emailOk) {
        echo "KESIMPULAN: SKIP — EMAIL_ORTU kosong/invalid\n\n";
        continue;
    }
    echo "KESIMPULAN: SEHARUSNYA BISA di-queue saat payment-email:dispatch jalan\n";
    echo "            Jika belum masuk → cron schedule:run kemungkinan belum otomatis\n\n";
}

echo "DONE (no email sent)\n";
