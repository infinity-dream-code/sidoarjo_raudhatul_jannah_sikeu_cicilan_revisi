<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\scctcust;
use App\Support\ExcelImportSheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportTagihanExcel implements WithMultipleSheets, ToCollection, WithHeadingRow
{
    public function __construct(
        private string $cacheKey = 'import_tagihan_excel',
        private int $sheetIndex = 0,
    ) {
    }

    public function sheets(): array
    {
        return [
            $this->sheetIndex => $this,
        ];
    }

    public function collection(Collection $collection): void
    {
        $processedData = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $rowData = $row->toArray();
            $nis = ExcelImportSheet::normalizeId($rowData['nis'] ?? null);
            if ($nis === '' || strcasecmp($nis, 'nis') === 0) {
                continue;
            }
            if (ExcelImportSheet::isTemplateSampleNis($nis)) {
                continue;
            }

            $rowData['nis'] = $nis;
            $rowData['status'] = 1;
            $status_ket = null;

            $checkData = scctcust::where('NOCUST', $nis)->first();
            if (!$checkData) {
                $rowData['status'] = 0;
                $status_ket = "NIS {$nis} tidak ditemukan";
            }

            $nominal = $rowData['nominal'] ?? null;
            if ($nominal === null || $nominal === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'Nominal tidak boleh kosong');
            }

            $cicilRaw = $rowData['cicil'] ?? $rowData['is_cicil'] ?? $rowData['iscicil'] ?? null;
            $cicil = $this->normalizeCicil($cicilRaw);
            if ($cicil === null) {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'Kolom CICIL harus diisi 1 (bisa cicil) atau 0 (tidak bisa cicil)');
                $rowData['cicil'] = $cicilRaw;
            } else {
                $rowData['cicil'] = $cicil;
            }

            $rowData['keterangan'] = $status_ket;
            $processedData[] = $rowData;
        }

        Cache::put($this->cacheKey, $processedData, now()->addMinutes(60));
    }

    public function headingRow(): int
    {
        return 1;
    }

    private function appendKet(?string $current, string $message): string
    {
        if ($current === null || $current === '') {
            return $message;
        }

        return $current . ', ' . $message;
    }

    /**
     * CICIL: 1 = bisa dicicil, 0 = tidak bisa dicicil.
     */
    private function normalizeCicil(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            $intVal = (int) $value;
            return in_array($intVal, [0, 1], true) ? $intVal : null;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'ya', 'yes', 'true', 'y'], true)) {
            return 1;
        }
        if (in_array($normalized, ['0', 'tidak', 'no', 'false', 'n'], true)) {
            return 0;
        }

        return null;
    }
}
