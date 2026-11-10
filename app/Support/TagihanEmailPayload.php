<?php

namespace App\Support;

use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctcust;
use Illuminate\Support\Facades\Log;
use Throwable;

class TagihanEmailPayload
{
    /**
     * Nama tagihan di scctbill.BILLNM sering terpotong (VARCHAR/procedure 30 char).
     * Ambil nama lengkap dari mst_tagihan bila tersedia.
     */
    public static function resolveFullBillName(?string $billNm): string
    {
        $billNm = trim((string) $billNm);
        if ($billNm === '') {
            return 'Tagihan';
        }

        try {
            $exact = mst_tagihan::query()
                ->where('tagihan', $billNm)
                ->value('tagihan');
            if (is_string($exact) && trim($exact) !== '') {
                return trim($exact);
            }

            // BILLNM terpotong → cari master yang diawali nama terpotong.
            if (mb_strlen($billNm) >= 25) {
                $prefix = mst_tagihan::query()
                    ->where('tagihan', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $billNm) . '%')
                    ->orderByRaw('CHAR_LENGTH(tagihan) DESC')
                    ->value('tagihan');
                if (is_string($prefix) && trim($prefix) !== '') {
                    return trim($prefix);
                }
            }
        } catch (Throwable $e) {
            Log::debug('resolveFullBillName fallback', ['billnm' => $billNm, 'error' => $e->getMessage()]);
        }

        return $billNm;
    }

    public static function adminFee(): int
    {
        // Biaya Admin VA dibebankan ke sekolah — tidak ditampilkan ke ortu di email.
        return 0;
    }

    public static function adminFeeLabel(): string
    {
        return 'Admin VA';
    }

    public static function paymentGuide(): string
    {
        return "PANDUAN/TATA CARA PEMBAYARAN:\n"
            . "1. Buka website/portal tagihan melalui link pada email\n"
            . "2. Periksa nominal tagihan yang tertera\n"
            . "3. Transfer sesuai nominal ke Virtual Account (VA) yang tertera\n"
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

        $itemName = self::resolveFullBillName($bill->BILLNM ?? null);
        $periode = trim((string) ($bill->BILLAC ?? $bill->BTA ?? ''));
        if ($periode !== '') {
            $itemName .= ' (' . $periode . ')';
        }

        $nis = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-')
            ? $siswa->NOCUST
            : ($siswa->nocust ?? $siswa->NUM2ND ?? '');
        $nama = (string) ($siswa->NMCUST ?? $siswa->nmcust ?? '-');
        $kelasLabel = trim((string) ($siswa->DESC02 ?? '') . ' ' . (string) ($siswa->DESC03 ?? ''));

        // isINSTALLABLE: 0 = Close (797789), 1 = Open/cicil (797790)
        $isInstallable = ((int) ($bill->isINSTALLABLE ?? 0) === 1) ? 1 : 0;
        $noVa = scctcust::showVA($nis, $isInstallable);
        $billType = $isInstallable === 1 ? 'Open Payment' : 'Full Payment';
        $expireDate = self::formatExpDate($bill->ExpDate ?? null);

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
            'billType' => $billType,
            'tipeTagihan' => $billType,
            'expireDate' => $expireDate,
            'tanggalBerakhir' => $expireDate,
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

    public static function formatExpDate(mixed $expDate): ?string
    {
        if ($expDate === null || $expDate === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $expDate)
                ->timezone(config('app.timezone', 'Asia/Jakarta'))
                ->format('d-m-Y H:i');
        } catch (\Throwable) {
            $raw = trim((string) $expDate);

            return $raw !== '' ? $raw : null;
        }
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
