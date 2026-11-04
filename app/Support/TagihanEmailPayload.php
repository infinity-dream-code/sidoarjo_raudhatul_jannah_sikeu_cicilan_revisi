<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\scctcust;
use Illuminate\Support\Facades\Log;
use Throwable;

class TagihanEmailPayload
{
    public static function adminFee(): int
    {
        return 2000;
    }

    public static function adminFeeLabel(): string
    {
        return 'Admin VA';
    }

    public static function paymentGuide(): string
    {
        return "PANDUAN/TATA CARA PEMBAYARAN:\n"
            . "1. Buka website/portal tagihan melalui link pada email\n"
            . "2. Periksa nominal: total tagihan + Rp 2.000 biaya Admin VA\n"
            . "   Contoh: tagihan Rp 500.000 → bayar Rp 502.000\n"
            . "3. Transfer sesuai nominal total ke Virtual Account (VA) yang tertera\n"
            . "4. Simpan bukti pembayaran";
    }

    public static function paymentLink(): string
    {
        return rtrim((string) config(
            'app.url_web_tagihan',
            'https://tagihan-raudlatuljannah-sidoarjo.smartpayment.co.id'
        ), '/') . '/';
    }

    /**
     * Build 1 email payload untuk 1 tagihan.
     * null = dilewati (email ortu kosong/invalid atau data tidak ditemukan).
     *
     * @return array<string, mixed>|null
     */
    public static function forBill(string $custId, string $billAa, ?string $sekolah = null): ?array
    {
        $siswaQuery = scctcust::query()->where('CUSTID', $custId);
        SchoolScope::apply($siswaQuery, 'scctcust', $sekolah);
        $siswa = $siswaQuery->first();
        if (!$siswa) {
            return null;
        }

        $email = trim((string) ($siswa->EMAIL_ORTU ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $billQuery = scctbill::query()
            ->where('CUSTID', $custId)
            ->where('AA', $billAa)
            ->where('FSTSBolehBayar', 1);

        $bill = $billQuery->first();
        if (!$bill) {
            return null;
        }

        $amounts = WhatsappTagihan::amounts(
            $bill->BILLAM,
            $bill->BILLPAID,
            $bill->PAYMENTLEFT,
            $bill->PAIDST
        );

        $itemName = trim((string) ($bill->BILLNM ?? 'Tagihan'));
        $periode = trim((string) ($bill->BILLAC ?? $bill->BTA ?? ''));
        if ($periode !== '') {
            $itemName .= ' (' . $periode . ')';
        }

        $nis = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-')
            ? $siswa->NOCUST
            : ($siswa->nocust ?? $siswa->NUM2ND ?? '');
        $nama = (string) ($siswa->NMCUST ?? $siswa->nmcust ?? '-');
        $kelasLabel = trim((string) ($siswa->DESC02 ?? '') . ' ' . (string) ($siswa->DESC03 ?? ''));
        $noVa = scctcust::showVA($nis, $bill->isINSTALLABLE ?? null);

        $subject = 'Informasi tagihan ananda ' . $nama;
        if ($itemName !== '') {
            $subject .= ' - ' . $itemName;
        }
        if ($kelasLabel !== '') {
            $subject .= ' (' . $kelasLabel . ')';
        }

        return [
            'nama' => $nama,
            'email' => $email,
            'nis' => $nis !== '' ? $nis : null,
            'kelas' => $kelasLabel !== '' ? $kelasLabel : null,
            'va' => $noVa !== '' ? $noVa : null,
            'subject' => $subject,
            'organizationName' => (string) (config('app.nama_instansi') ?: 'Raudlatul Jannah Sidoarjo'),
            'paymentLink' => self::paymentLink(),
            'totalAmount' => $amounts['total'],
            'outstandingAmount' => $amounts['sisa'],
            'adminFee' => self::adminFee(),
            'adminFeeLabel' => self::adminFeeLabel(),
            'paymentGuide' => self::paymentGuide(),
            'items' => [[
                'name' => $itemName,
                'amount' => $amounts['sisa'] > 0 ? $amounts['sisa'] : $amounts['total'],
            ]],
        ];
    }

    /**
     * @param  array<int, array{custid:string|int, aa:string|int}>  $billRefs
     * @return array<int, array<string, mixed>>
     */
    public static function buildMany(array $billRefs, ?string $sekolah = null): array
    {
        $messages = [];
        foreach ($billRefs as $ref) {
            $custId = trim((string) ($ref['custid'] ?? $ref['CUSTID'] ?? ''));
            $aa = trim((string) ($ref['aa'] ?? $ref['AA'] ?? ''));
            if ($custId === '' || $aa === '') {
                continue;
            }

            $payload = self::forBill($custId, $aa, $sekolah);
            if ($payload !== null) {
                $messages[] = $payload;
            }
        }

        return $messages;
    }

    /**
     * Antrikan email di background setelah response HTTP selesai (tidak menambah loading UI).
     *
     * @param  array<int, array{custid:string|int, aa:string|int}>  $billRefs
     */
    public static function dispatchEnqueueAfterResponse(array $billRefs, ?string $sekolah = null): void
    {
        $billRefs = array_values(array_filter($billRefs, static function ($ref) {
            return is_array($ref)
                && trim((string) ($ref['custid'] ?? $ref['CUSTID'] ?? '')) !== ''
                && trim((string) ($ref['aa'] ?? $ref['AA'] ?? '')) !== '';
        }));

        if ($billRefs === []) {
            return;
        }

        dispatch(function () use ($billRefs, $sekolah) {
            try {
                $messages = self::buildMany($billRefs, $sekolah);
                if ($messages === []) {
                    return;
                }

                $result = EmailBroadcaster::enqueueBilling($messages);
                $queued = (int) ($result['queued'] ?? 0);
                $immediateMax = max(0, (int) config('email_broadcaster.immediate_max', 3));

                if ($queued > 0 && $queued <= $immediateMax) {
                    EmailBroadcaster::processNow();
                }
            } catch (Throwable $e) {
                Log::warning('auto enqueue tagihan email gagal', [
                    'count' => count($billRefs),
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }
}
