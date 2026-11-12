<?php

namespace App\Support;

class Terbilang
{
    public static function rupiah(int|float $amount): string
    {
        $n = (int) round(abs($amount));
        if ($n === 0) {
            return 'Nol Rupiah';
        }

        return self::spell($n) . ' Rupiah';
    }

    private static function spell(int $n): string
    {
        $satu = [
            '', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan',
            'Sepuluh', 'Sebelas',
        ];

        if ($n < 12) {
            return $satu[$n];
        }
        if ($n < 20) {
            return $satu[$n - 10] . ' Belas';
        }
        if ($n < 100) {
            return trim($satu[intdiv($n, 10)] . ' Puluh ' . self::spell($n % 10));
        }
        if ($n < 200) {
            return trim('Seratus ' . self::spell($n - 100));
        }
        if ($n < 1000) {
            return trim($satu[intdiv($n, 100)] . ' Ratus ' . self::spell($n % 100));
        }
        if ($n < 2000) {
            return trim('Seribu ' . self::spell($n - 1000));
        }
        if ($n < 1000000) {
            return trim(self::spell(intdiv($n, 1000)) . ' Ribu ' . self::spell($n % 1000));
        }
        if ($n < 1000000000) {
            return trim(self::spell(intdiv($n, 1000000)) . ' Juta ' . self::spell($n % 1000000));
        }
        if ($n < 1000000000000) {
            return trim(self::spell(intdiv($n, 1000000000)) . ' Miliar ' . self::spell($n % 1000000000));
        }

        return trim(self::spell(intdiv($n, 1000000000000)) . ' Triliun ' . self::spell($n % 1000000000000));
    }
}
