<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\scctcust;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillNoticeEmailPayload
{
    public static function enabled(): bool
    {
        return (bool) config('email_broadcaster.bill_notice_enabled', true);
    }

    /**
     * Notifikasi edit nominal / nama tagihan.
     *
     * @param  array{oldAmount?:int|float|null,newAmount?:int|float|null,oldBillName?:string|null,newBillName?:string|null}  $changes
     * @return array<string, mixed>|null
     */
    public static function forBillUpdate(string $billAa, array $changes = []): ?array
    {
        return self::build($billAa, 'bill_update', $changes);
    }

    /**
     * Notifikasi ganti tanggal expired.
     *
     * @param  array{oldExpireDate?:string|null,newExpireDate?:string|null}  $changes
     * @return array<string, mixed>|null
     */
    public static function forExpUpdate(string $billAa, array $changes = []): ?array
    {
        return self::build($billAa, 'exp_update', $changes);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    private static function build(string $billAa, string $method, array $changes): ?array
    {
        $aa = trim($billAa);
        if ($aa === '') {
            return null;
        }

        $bill = scctbill::query()->where('AA', $aa)->first();
        if (!$bill) {
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
        $billType = $isInstallable === 1 ? 'Open Payment' : 'Full Payment';

        $currentName = TagihanEmailPayload::resolveFullBillName($bill->BILLNM ?? null);
        $oldBillName = TagihanEmailPayload::resolveFullBillName(
            (string) ($changes['oldBillName'] ?? $bill->BILLNM ?? $currentName)
        );
        $newBillName = TagihanEmailPayload::resolveFullBillName(
            (string) ($changes['newBillName'] ?? $bill->BILLNM ?? $currentName)
        );
        if ($newBillName === '') {
            $newBillName = $currentName;
        }

        $oldAmount = isset($changes['oldAmount']) ? (int) $changes['oldAmount'] : (int) ($bill->BILLAM ?? 0);
        $newAmount = isset($changes['newAmount']) ? (int) $changes['newAmount'] : (int) ($bill->BILLAM ?? 0);

        $expireDate = TagihanEmailPayload::formatExpDate($bill->ExpDate ?? null);
        $oldExpireDate = isset($changes['oldExpireDate'])
            ? TagihanEmailPayload::formatExpDate($changes['oldExpireDate'])
            : null;
        $newExpireDate = isset($changes['newExpireDate'])
            ? TagihanEmailPayload::formatExpDate($changes['newExpireDate'])
            : $expireDate;

        $isExp = $method === 'exp_update';
        $subject = $isExp
            ? ('Perubahan tanggal expired tagihan ananda ' . $nama)
            : ('Perubahan data tagihan ananda ' . $nama);
        if ($newBillName !== '') {
            $subject .= ' - ' . $newBillName;
        }

        return [
            'method' => $method,
            'nama' => $nama,
            'email' => $email,
            'nis' => $nis !== '' ? $nis : null,
            'kelas' => $kelasLabel !== '' ? $kelasLabel : null,
            'va' => $noVa !== '' ? $noVa : null,
            'billType' => $billType,
            'tipeTagihan' => $billType,
            'subject' => $subject,
            'organizationName' => (string) (config('app.nama_instansi') ?: 'Raudlatul Jannah Sidoarjo'),
            'orderNumber' => (string) ($bill->BILLCD ?? $aa),
            'aa' => $aa,
            'billName' => $newBillName,
            'oldBillName' => $oldBillName !== '' ? $oldBillName : null,
            'newBillName' => $newBillName,
            'oldAmount' => $oldAmount,
            'newAmount' => $newAmount,
            'totalAmount' => $newAmount,
            'expireDate' => $newExpireDate ?? $expireDate,
            'oldExpireDate' => $oldExpireDate,
            'newExpireDate' => $newExpireDate,
            'items' => [[
                'name' => $newBillName,
                'amount' => $newAmount,
            ]],
        ];
    }

    /**
     * @param  array<int, array{aa:string,method:string,changes?:array<string,mixed>}>  $items
     */
    public static function dispatchMany(array $items): array
    {
        if (!self::enabled()) {
            return ['queued' => 0, 'skipped' => count($items)];
        }

        $messages = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $aa = trim((string) ($item['aa'] ?? ''));
            $method = strtolower(trim((string) ($item['method'] ?? 'bill_update')));
            $changes = is_array($item['changes'] ?? null) ? $item['changes'] : [];
            if ($aa === '') {
                continue;
            }

            $payload = $method === 'exp_update'
                ? self::forExpUpdate($aa, $changes)
                : self::forBillUpdate($aa, $changes);

            if ($payload !== null) {
                $messages[] = $payload;
            }
        }

        if ($messages === []) {
            return ['queued' => 0, 'skipped' => count($items)];
        }

        try {
            $result = EmailBroadcaster::enqueueBillNotice($messages);
        } catch (Throwable $e) {
            Log::warning('bill notice email enqueue gagal', [
                'count' => count($messages),
                'error' => $e->getMessage(),
            ]);

            return ['queued' => 0, 'skipped' => count($items)];
        }

        $queued = (int) ($result['queued'] ?? 0);
        $immediateMax = max(0, (int) config('email_broadcaster.immediate_max', 3));
        if ($queued > 0 && $queued <= $immediateMax) {
            try {
                EmailBroadcaster::processNow();
            } catch (Throwable $e) {
                Log::warning('bill notice process-now gagal', ['error' => $e->getMessage()]);
            }
        }

        return [
            'queued' => $queued,
            'skipped' => max(0, count($items) - $queued),
        ];
    }

    /**
     * @param  array<int, array{aa:string,method:string,changes?:array<string,mixed>}>  $items
     */
    public static function dispatchAfterResponse(array $items): void
    {
        if (!self::enabled()) {
            return;
        }

        $items = array_values(array_filter($items, static function ($item) {
            return is_array($item) && trim((string) ($item['aa'] ?? '')) !== '';
        }));
        if ($items === []) {
            return;
        }

        dispatch(function () use ($items) {
            try {
                self::dispatchMany($items);
            } catch (Throwable $e) {
                Log::warning('bill notice afterResponse gagal', [
                    'count' => count($items),
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }
}
