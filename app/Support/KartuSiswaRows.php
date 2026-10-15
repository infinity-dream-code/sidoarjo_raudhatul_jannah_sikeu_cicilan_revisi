<?php

namespace App\Support;

use App\Models\scctbill;
use Throwable;

/**
 * Baris kartu tagihan siswa: 1 baris per tagihan (scctbill.AA) dengan
 * jumlah tagihan, terbayar, sisa, dan status lunas — aman untuk tagihan cicilan.
 */
class KartuSiswaRows
{
    /**
     * @param  array<int, array<string, mixed>>  $tagihans
     * @return array<int, array{bta: string, nama: string, total: int, terbayar: int, sisa: int, lunas: bool}>
     */
    public static function build(array $tagihans): array
    {
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
                    ->get(['AA', 'BTA', 'BILLNM', 'BILLAM', 'BILLPAID', 'PAYMENTLEFT', 'PAIDST'])
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

                $rows[] = self::fromAmounts(
                    (string) ($bill->BTA ?? $row['BTA'] ?? ''),
                    (string) ($bill->BILLNM ?? $row['BILLNM'] ?? ''),
                    (int) ($bill->BILLAM ?? 0),
                    $bill->BILLPAID,
                    $bill->PAYMENTLEFT,
                    (int) ($bill->PAIDST ?? 0) === 1,
                );
                continue;
            }

            $total = (int) ($row['BILLAM_TOTAL'] ?? $row['BILLAM'] ?? 0);
            $lunas = (int) ($row['PAIDST'] ?? 0) === 1;
            $rows[] = self::fromAmounts(
                (string) ($row['BTA'] ?? ''),
                (string) ($row['BILLNM'] ?? ''),
                $total,
                $row['BILLPAID'] ?? ($lunas ? $total : 0),
                $row['PAYMENTLEFT'] ?? null,
                $lunas,
            );
        }

        return $rows;
    }

    private static function fromAmounts(string $bta, string $nama, int $total, mixed $paid, mixed $paymentLeft, bool $paidFlag): array
    {
        if ($paymentLeft === null || $paymentLeft === '') {
            $sisa = $paidFlag ? 0 : max(0, $total - (int) ($paid ?? 0));
        } else {
            $sisa = max(0, (int) $paymentLeft);
        }

        return [
            'bta' => $bta,
            'nama' => $nama,
            'total' => $total,
            'terbayar' => max(0, $total - $sisa),
            'sisa' => $sisa,
            'lunas' => $paidFlag || $sisa <= 0,
        ];
    }
}
