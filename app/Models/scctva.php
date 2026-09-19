<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class scctva extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = "scctva";

    protected $primaryKey = "ID";

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Nonaktifkan VA aktif (STATUS=0) untuk siswa ini, berdasarkan NOCUST dan/atau CUSTID.
     */
    public static function deactivateForStudent(?string $nocust = null, mixed $custId = null): int
    {
        $nocust = trim((string) $nocust);
        $custId = ($custId === null || $custId === '') ? null : $custId;

        if (($nocust === '' || $nocust === '-') && $custId === null) {
            return 0;
        }

        $updated = static::query()
            ->where(function ($query) use ($nocust, $custId) {
                if ($nocust !== '' && $nocust !== '-') {
                    $query->where('NOCUST', $nocust);
                }
                if ($custId !== null) {
                    $query->orWhere('CUSTID', $custId);
                }
            })
            ->where('STATUS', '!=', 0)
            ->update(['STATUS' => 0]);

        if ($updated > 0) {
            Log::info('scctva.deactivated', [
                'nocust' => $nocust !== '' ? $nocust : null,
                'custid' => $custId,
                'rows' => $updated,
            ]);
        }

        return $updated;
    }

    public static function generateShareToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function shareUrl(): ?string
    {
        $token = trim((string) ($this->SHARE_TOKEN ?? ''));
        if ($token === '') {
            return null;
        }

        return route('cara-bayar.show', ['token' => $token]);
    }

    public function displayNova(): string
    {
        $stored = preg_replace('/\D/', '', (string) ($this->NOVA ?? ''));
        // Sudah tersimpan full VA (16 digit) — pakai langsung
        if (strlen($stored) >= 12) {
            return $stored;
        }

        $nis = trim((string) ($this->NOCUST ?? $this->NOVA ?? ''));
        if ($nis === '' || $nis === '-') {
            return '';
        }

        return scctcust::showVA($nis, $this->resolveInstallableFlag());
    }

    /**
     * Tentukan Open/Close dari tagihan yang terhubung di ArrayTagihan.
     * 1 = VA Open (cicil), 0 = VA Close.
     */
    public function resolveInstallableFlag(): int
    {
        $aas = array_values(array_filter(array_map(
            static fn ($aa) => (int) trim((string) $aa),
            explode(',', (string) ($this->ArrayTagihan ?? ''))
        )));

        if ($aas === []) {
            return 0;
        }

        $flags = scctbill::query()
            ->whereIn('AA', $aas)
            ->pluck('isINSTALLABLE')
            ->map(static fn ($v) => ((int) $v === 1) ? 1 : 0)
            ->unique()
            ->values();

        if ($flags->count() === 1) {
            return (int) $flags->first();
        }

        // Campuran / tidak ketemu — default Close
        return 0;
    }

    /**
     * @return array<int, array{aa:int, nama:string, amount:int}>
     */
    public function parsedItems(): array
    {
        $aas = array_values(array_filter(array_map('trim', explode(',', (string) ($this->ArrayTagihan ?? '')))));
        $amounts = array_values(array_filter(array_map('trim', explode(',', (string) ($this->BILLAM ?? ''))), 'strlen'));

        $items = [];
        foreach ($aas as $i => $aa) {
            $items[] = [
                'aa' => (int) $aa,
                'nama' => '',
                'amount' => (int) ($amounts[$i] ?? 0),
            ];
        }

        if ($items === []) {
            return [];
        }

        $names = scctbill::query()
            ->whereIn('AA', array_column($items, 'aa'))
            ->pluck('BILLNM', 'AA');

        foreach ($items as &$item) {
            $item['nama'] = (string) ($names[$item['aa']] ?? ('Tagihan #' . $item['aa']));
        }
        unset($item);

        return $items;
    }
}
