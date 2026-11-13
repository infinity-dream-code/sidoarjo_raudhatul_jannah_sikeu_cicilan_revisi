<?php

namespace App\Support;

use App\Models\scctbill;
use Carbon\Carbon;
use Throwable;

/**
 * Baris kartu tagihan siswa — filter status bergantung menu pemanggil:
 * - unpaid: Data Tagihan → judul "CETAK KARTU TAGIHAN" (belum lunas saja)
 * - paid: Data Tagihan Lunas → judul "KARTU LUNAS" (lunas saja)
 * - all: Data Pembayaran → judul "CETAK KARTU BAYAR" (campur lunas + belum)
 */
class KartuSiswaRows
{
    public const MODE_ALL = 'all';
    public const MODE_UNPAID = 'unpaid';
    public const MODE_PAID = 'paid';

    /**
     * @param  array<int, array<string, mixed>>  $tagihans
     * @param  self::MODE_*|string  $mode
     * @return array<int, array{bta: string, periode: string, nama: string, total: int, terbayar: int, sisa: int, expdate: string, lunas: bool}>
     */
    public static function build(array $tagihans, string $mode = self::MODE_ALL): array
    {
        $mode = self::normalizeMode($mode);

        $billIds = [];
        foreach ($tagihans as $row) {
            $aa = $row['AA'] ?? null;
            if ($aa !== null && $aa !== '') {
                $billIds[] = $aa;
            }
        }
        $billIds = array_values(array_unique($billIds));

        $bills = collect();
        if (!empty($billIds)) {
            try {
                $bills = scctbill::query()
                    ->whereIn('AA', $billIds)
                    ->get(['AA', 'BTA', 'BILLAC', 'BILLNM', 'BILLAM', 'BILLPAID', 'PAYMENTLEFT', 'PAIDST', 'ExpDate', 'PAIDDT'])
                    ->keyBy(fn ($bill) => (string) $bill->AA);
            } catch (Throwable) {
                $bills = collect();
            }
        }

        $rows = [];
        $seen = [];
        foreach ($tagihans as $row) {
            $aa = (string) ($row['AA'] ?? '');
            $bill = $aa !== '' ? $bills->get($aa) : null;

            if ($bill) {
                if (isset($seen[$aa])) {
                    continue;
                }
                $seen[$aa] = true;

                $built = self::fromAmounts(
                    (string) ($bill->BTA ?? $row['BTA'] ?? ''),
                    (string) ($bill->BILLAC ?? $row['BILLAC'] ?? $row['BTA'] ?? ''),
                    (string) ($bill->BILLNM ?? $row['BILLNM'] ?? ''),
                    (int) ($bill->BILLAM ?? 0),
                    $bill->BILLPAID,
                    $bill->PAYMENTLEFT,
                    (int) ($bill->PAIDST ?? 0) === 1,
                    $bill->ExpDate ?? ($row['ExpDate_raw'] ?? $row['ExpDate'] ?? null),
                    $bill->PAIDDT ?? ($row['PAIDDT'] ?? null),
                );
            } else {
                $total = (int) ($row['BILLAM_TOTAL'] ?? $row['BILLAM'] ?? 0);
                $lunas = (int) ($row['PAIDST'] ?? 0) === 1;
                $built = self::fromAmounts(
                    (string) ($row['BTA'] ?? ''),
                    (string) ($row['BILLAC'] ?? $row['BTA'] ?? ''),
                    (string) ($row['BILLNM'] ?? ''),
                    $total,
                    $row['BILLPAID'] ?? ($lunas ? $total : 0),
                    $row['PAYMENTLEFT'] ?? null,
                    $lunas,
                    $row['ExpDate_raw'] ?? $row['ExpDate'] ?? null,
                    $row['PAIDDT'] ?? null,
                );
            }

            if (!self::matchesMode($built, $mode)) {
                continue;
            }

            $rows[] = $built;
        }

        return $rows;
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return match ($mode) {
            self::MODE_UNPAID, 'belum', 'belum_lunas', 'unpaid_only' => self::MODE_UNPAID,
            self::MODE_PAID, 'lunas', 'sudah', 'sudah_bayar', 'paid_only' => self::MODE_PAID,
            default => self::MODE_ALL,
        };
    }

    /**
     * @param  array{lunas: bool, sisa: int}  $built
     */
    private static function matchesMode(array $built, string $mode): bool
    {
        $lunas = !empty($built['lunas']) || (int) ($built['sisa'] ?? 0) <= 0;

        return match ($mode) {
            self::MODE_UNPAID => !$lunas,
            self::MODE_PAID => $lunas,
            default => true,
        };
    }

    /**
     * @return array{bta: string, periode: string, nama: string, total: int, terbayar: int, sisa: int, expdate: string, paiddt: string, lunas: bool}
     */
    private static function fromAmounts(
        string $bta,
        string $periode,
        string $nama,
        int $total,
        mixed $paid,
        mixed $paymentLeft,
        bool $paidFlag,
        mixed $expDate,
        mixed $paidDt = null,
    ): array {
        if ($paymentLeft === null || $paymentLeft === '') {
            $sisa = $paidFlag ? 0 : max(0, $total - (int) ($paid ?? 0));
        } else {
            $sisa = max(0, (int) $paymentLeft);
        }

        return [
            'bta' => $bta,
            'periode' => $periode !== '' ? $periode : $bta,
            'nama' => $nama,
            'total' => $total,
            'terbayar' => max(0, $total - $sisa),
            'sisa' => $sisa,
            'expdate' => self::formatExpDate($expDate),
            'paiddt' => self::formatExpDate($paidDt),
            'lunas' => $paidFlag || $sisa <= 0,
        ];
    }

    private static function formatExpDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_string($value) && preg_match('/^\d{2}-\d{2}-\d{4}/', $value)) {
            return substr($value, 0, 10);
        }

        try {
            return Carbon::parse($value)->format('d-m-Y');
        } catch (Throwable) {
            return '-';
        }
    }
}
