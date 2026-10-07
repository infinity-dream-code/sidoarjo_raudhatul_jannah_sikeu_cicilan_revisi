<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\mst_thn_aka;
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
        $parsedRows = [];

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

            $namaTagihan = trim((string) ($rowData['keterangan'] ?? $rowData['nama_tagihan'] ?? ''));

            $parsedRows[] = [
                'nis' => $nis,
                'nama' => trim((string) ($rowData['nama'] ?? '')),
                'unit' => trim((string) ($rowData['unit'] ?? '')),
                'kelas' => is_numeric($rowData['kelas'] ?? null)
                    ? (string) (int) $rowData['kelas']
                    : trim((string) ($rowData['kelas'] ?? '')),
                'kelompok' => trim((string) ($rowData['kelompok'] ?? '')),
                'angkatan' => trim((string) ($rowData['angkatan'] ?? '')),
                'no_wa' => $this->normalizeNoWa(
                    $rowData['no_wa'] ?? $rowData['nowa'] ?? $rowData['no wa'] ?? null
                ),
                'nama_tagihan' => $namaTagihan,
                'nominal' => $rowData['nominal'] ?? null,
                'cicil' => $rowData['cicil'] ?? $rowData['is_cicil'] ?? $rowData['iscicil'] ?? null,
            ];
        }

        if ($parsedRows === []) {
            Cache::forget($this->cacheKey);

            return;
        }

        $nisCounts = [];
        foreach ($parsedRows as $row) {
            $nisCounts[$row['nis']] = ($nisCounts[$row['nis']] ?? 0) + 1;
        }

        $thnAkaSet = array_flip(
            mst_thn_aka::pluck('thn_aka')
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '')
                ->all()
        );

        $processedData = [];

        foreach ($parsedRows as $rowData) {
            $rowData['status'] = 1;
            $status_ket = null;

            if (($nisCounts[$rowData['nis']] ?? 0) > 1) {
                $rowData['status'] = 0;
                $status_ket = "NIS {$rowData['nis']} double, tolong perbaiki";
            }

            if ($rowData['nama'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'NAMA tidak boleh kosong');
            }

            if ($rowData['unit'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'UNIT tidak boleh kosong');
            }

            if ($rowData['kelas'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'KELAS tidak boleh kosong');
            }

            if ($rowData['kelompok'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'KELOMPOK tidak boleh kosong');
            }

            if ($rowData['angkatan'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'ANGKATAN tidak boleh kosong');
            } elseif (!isset($thnAkaSet[$rowData['angkatan']])) {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, "ANGKATAN {$rowData['angkatan']} tidak ditemukan di master tahun akademik");
            }

            if ($rowData['nama_tagihan'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'KETERANGAN (nama tagihan) tidak boleh kosong');
            }

            $nominal = $rowData['nominal'];
            if ($nominal === null || $nominal === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'NOMINAL tidak boleh kosong');
            }

            $cicil = $this->normalizeCicil($rowData['cicil']);
            if ($cicil === null) {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'Kolom CICIL harus diisi 1 (bisa cicil) atau 0 (tidak bisa cicil)');
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

    private function normalizeNoWa(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $digits = preg_replace('/\D+/', '', sprintf('%.0f', $value));
        } else {
            $digits = preg_replace('/\D+/', '', trim((string) $value));
        }

        if ($digits === '' || strlen($digits) > 50) {
            return null;
        }

        return $digits;
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
