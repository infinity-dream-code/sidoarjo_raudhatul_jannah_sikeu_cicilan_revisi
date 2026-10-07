<?php

namespace App\Imports\Keuangan\TagihanSiswa;

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
            $genderRaw = $rowData['gender']
                ?? $rowData['jenis_kelamin']
                ?? $rowData['jk']
                ?? null;

            $parsedRows[] = [
                'nis' => $nis,
                'nama' => trim((string) ($rowData['nama'] ?? '')),
                'gender' => $genderRaw,
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

        // NIS sama boleh untuk beberapa tagihan (beda KETERANGAN/NOMINAL/CICIL).
        // Konflik hanya jika data siswa (selain 3 kolom itu) berbeda untuk NIS yang sama.
        $nisIdentityMap = [];
        foreach ($parsedRows as $row) {
            $nis = $row['nis'];
            $identity = $this->studentIdentityKey($row);
            $nisIdentityMap[$nis][$identity] = true;
        }
        $nisConflict = [];
        foreach ($nisIdentityMap as $nis => $identities) {
            if (count($identities) > 1) {
                $nisConflict[$nis] = true;
            }
        }

        $processedData = [];

        foreach ($parsedRows as $rowData) {
            $rowData['status'] = 1;
            $status_ket = null;

            if (isset($nisConflict[$rowData['nis']])) {
                $rowData['status'] = 0;
                $status_ket = "NIS {$rowData['nis']} dipakai untuk data siswa berbeda, tolong perbaiki";
            }

            if ($rowData['nama'] === '') {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'NAMA tidak boleh kosong');
            }

            $gender = $this->normalizeGender($rowData['gender'] ?? null);
            if (($rowData['gender'] ?? null) !== null && trim((string) $rowData['gender']) !== '' && $gender === null) {
                $rowData['status'] = 0;
                $status_ket = $this->appendKet($status_ket, 'GENDER harus L atau P');
                $rowData['gender'] = $rowData['gender'];
            } else {
                $rowData['gender'] = $gender;
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
            }
            // ANGKATAN baru (belum di master) akan dibuat otomatis saat simpan.

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

    /**
     * Identitas siswa (bukan tagihan). KETERANGAN/NOMINAL/CICIL sengaja diabaikan.
     */
    private function studentIdentityKey(array $row): string
    {
        $gender = $this->normalizeGender($row['gender'] ?? null) ?? '';

        return implode('|', [
            mb_strtolower(trim((string) ($row['nama'] ?? ''))),
            $gender,
            mb_strtolower(trim((string) ($row['unit'] ?? ''))),
            mb_strtolower(trim((string) ($row['kelas'] ?? ''))),
            mb_strtolower(trim((string) ($row['kelompok'] ?? ''))),
            mb_strtolower(trim((string) ($row['angkatan'] ?? ''))),
            trim((string) ($row['no_wa'] ?? '')),
        ]);
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
     * GENDER: L = Laki-Laki, P = Perempuan.
     */
    private function normalizeGender(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === 'L' || $normalized === 'P') {
            return $normalized;
        }

        $lower = strtolower(trim((string) $value));
        $lower = str_replace(['-', '_'], ' ', $lower);
        $lower = preg_replace('/\s+/', ' ', $lower) ?? $lower;

        if (in_array($lower, ['laki', 'laki laki', 'pria', 'male', 'm'], true)) {
            return 'L';
        }
        if (in_array($lower, ['perempuan', 'wanita', 'female', 'f'], true)) {
            return 'P';
        }

        return null;
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
