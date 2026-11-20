<?php

/**
 * Tes bayar kecil + kirim email konfirmasi untuk 1 NIS.
 * Usage: php scripts/test_bayar_email_nis.php 1234567006 [nominal]
 */

use App\Models\PaymentEmailLog;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\sccttran;
use App\Support\EmailBroadcaster;
use App\Support\PaymentEmailPayload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$nis = trim((string) ($argv[1] ?? '1234567006'));
$nominal = max(1000, (int) ($argv[2] ?? 100000));

echo "NIS={$nis} nominal={$nominal}\n";

$siswa = scctcust::query()
    ->where('NOCUST', $nis)
    ->orWhere('NUM2ND', $nis)
    ->first();

if (!$siswa) {
    fwrite(STDERR, "Siswa tidak ditemukan\n");
    exit(1);
}

$email = trim((string) ($siswa->EMAIL_ORTU ?? ''));
echo "CUSTID={$siswa->CUSTID} nama={$siswa->NMCUST} email={$email}\n";
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "EMAIL_ORTU kosong/invalid — email tidak bisa dikirim\n");
    exit(2);
}

$bill = scctbill::query()
    ->where('CUSTID', $siswa->CUSTID)
    ->where('FSTSBolehBayar', 1)
    ->where('PAYMENTLEFT', '>', 0)
    ->orderByDesc('isINSTALLABLE')
    ->orderByDesc('AA')
    ->first();

if (!$bill) {
    fwrite(STDERR, "Tidak ada tagihan belum lunas\n");
    exit(3);
}

$aa = (string) $bill->AA;
$billAm = (int) ($bill->BILLAM ?? 0);
$paidBefore = (int) ($bill->BILLPAID ?? 0);
$leftBefore = (int) ($bill->PAYMENTLEFT ?? max(0, $billAm - $paidBefore));
$pay = min($nominal, $leftBefore);
$paidAfter = $paidBefore + $pay;
$leftAfter = max(0, $billAm - $paidAfter);
$instAfter = (int) ($bill->INSTALLMENT ?? 0) + 1;
$isInstallable = (int) ($bill->isINSTALLABLE ?? 0) === 1 ? 1 : 0;

echo "AA={$aa} BILLNM={$bill->BILLNM} BILLAM={$billAm} paidBefore={$paidBefore} pay={$pay} installable={$isInstallable}\n";

// Hapus log email agar delta terdeteksi sebagai pembayaran baru.
PaymentEmailLog::query()->where('aa', $aa)->delete();
echo "payment_email_logs cleared for AA\n";

$now = date('Y-m-d H:i:s');
$transno = 'TEST' . date('YmdHis');

DB::connection('DATA_MYSQL')->beginTransaction();
try {
    $bill->update([
        'BILLPAID' => $paidAfter,
        'PAYMENTLEFT' => $leftAfter,
        'PAIDDT' => date('Y-m-d'),
        'PAIDDT_ACTUAL' => $now,
        'PAIDST' => $leftAfter <= 0 ? 1 : 0,
        'INSTALLMENT' => $instAfter,
        'FIDBANK' => $bill->FIDBANK ?: 'TEST',
        'NOREFF' => 'TELLER',
        'TRANSNO' => $transno,
    ]);

    sccttran::create([
        'CUSTID' => $siswa->CUSTID,
        'METODE' => 'FROM TELLER',
        'TRXDATE' => $now,
        'NOREFF' => 'TELLER',
        'FIDBANK' => 'TEST',
        'DEBET' => $pay,
        'KREDIT' => $pay,
        'BILLID' => $aa,
        'BILLTARGET' => (string) ($bill->BILLNM ?? ''),
        'INSTALLMENT' => $instAfter,
        'TRANSNO' => $transno,
        'isreversal' => 0,
    ]);

    DB::connection('DATA_MYSQL')->commit();
} catch (Throwable $e) {
    DB::connection('DATA_MYSQL')->rollBack();
    fwrite(STDERR, 'Gagal update bayar: ' . $e->getMessage() . "\n");
    exit(4);
}

echo "DB updated paidAfter={$paidAfter} leftAfter={$leftAfter} installment={$instAfter}\n";

$result = PaymentEmailPayload::dispatchForBill($aa, $paidAfter, $instAfter);
echo 'dispatch=' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

if (!empty($result['queued'])) {
    try {
        $proc = EmailBroadcaster::processNow();
        echo 'processNow=' . json_encode($proc, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo 'processNow ERR: ' . $e->getMessage() . "\n";
    }
} else {
    echo "TIDAK QUEUED — reason=" . ($result['reason'] ?? '-') . "\n";
}

echo "DONE\n";
