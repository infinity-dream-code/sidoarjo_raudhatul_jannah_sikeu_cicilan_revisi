<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class scctcust extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = 'scctcust';

    protected $primaryKey = 'CUSTID';

    public $timestamps = false;

    public $incrementing = false;

    public static function vaPrefix(): string
    {
        $raw = preg_replace('/\D/', '', (string) config('app.nova', self::vaPrefixClose()));

        return $raw !== '' ? $raw : self::vaPrefixClose();
    }

    /**
     * VA Close — tagihan lunas sekaligus (scctbill.isINSTALLABLE = 0).
     */
    public static function vaPrefixClose(): string
    {
        $raw = preg_replace('/\D/', '', (string) config('app.nova_close', '797789'));

        return $raw !== '' ? $raw : '797789';
    }

    /**
     * VA Open / cicil — tagihan boleh dicicil (scctbill.isINSTALLABLE = 1).
     */
    public static function vaPrefixOpen(): string
    {
        $raw = preg_replace('/\D/', '', (string) config('app.nova_open', '797790'));

        return $raw !== '' ? $raw : '797790';
    }

    /**
     * Pilih prefix VA berdasarkan flag cicil di scctbill.
     * 0 = Close (797789), 1 = Open/cicil (797790).
     */
    public static function vaPrefixForInstallable(mixed $isInstallable): string
    {
        return ((int) $isInstallable === 1)
            ? self::vaPrefixOpen()
            : self::vaPrefixClose();
    }

    public static function vaTotalLength(): int
    {
        return 16;
    }

    public static function showVAMTS($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVAMA($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVASpp($nis): string
    {
        return self::showVA($nis);
    }

    public static function showVASaku($nis): string
    {
        return self::showVA($nis);
    }

    /**
     * Format No. VA.
     * @param  mixed  $isInstallable  null = prefix default; 0 = Close; 1 = Open (cicil)
     */
    public static function showVA($nis, mixed $isInstallable = null): string
    {
        $prefix = $isInstallable === null
            ? self::vaPrefix()
            : self::vaPrefixForInstallable($isInstallable);

        return self::formatVA($prefix, $nis);
    }

    public static function formatVA(string $prefix, mixed $nis): string
    {
        $prefixDigits = preg_replace('/\D/', '', $prefix);
        $nisDigits = preg_replace('/\D/', '', (string) $nis);

        if ($prefixDigits === '' || $nisDigits === '' || $nisDigits === '-') {
            return '';
        }

        $suffixLength = max(1, self::vaTotalLength() - strlen($prefixDigits));

        return $prefixDigits . str_pad($nisDigits, $suffixLength, '0', STR_PAD_LEFT);
    }

    public static function vaTypeLabel(mixed $isInstallable): string
    {
        return ((int) $isInstallable === 1) ? 'Open (Cicil)' : 'Close';
    }

    /**
     * Deteksi jenis VA dari REFFBANK / nomor VA transaksi.
     * return 1=Open, 0=Close, null=tidak dikenal.
     */
    public static function resolveInstallableFromReffBank(?string $reffBank): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $reffBank);
        if ($digits === '') {
            return null;
        }

        $open = self::vaPrefixOpen();
        $close = self::vaPrefixClose();

        if ($open !== '' && str_starts_with($digits, $open)) {
            return 1;
        }
        if ($close !== '' && str_starts_with($digits, $close)) {
            return 0;
        }

        return null;
    }

    public static function reffBankPrefix(mixed $isInstallable): string
    {
        return self::vaPrefixForInstallable($isInstallable);
    }

    public static function nextCustId(): int
    {
        $max = self::query()->max('CUSTID');

        return ((int) $max) + 1;
    }

    public function rawNis(): string
    {
        foreach ($this->getAttributes() as $name => $value) {
            if (strcasecmp((string) $name, 'nocust') !== 0) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        foreach ($this->getAttributes() as $name => $value) {
            if (strcasecmp((string) $name, 'num2nd') !== 0) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    protected $guarded = [];
}
