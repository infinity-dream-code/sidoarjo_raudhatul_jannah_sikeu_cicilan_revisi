<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Models\mst_kelas;
use App\Models\mst_sekolah;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\EnsureImportSchoolClass;
use App\Support\ExcelImportSheet;
use App\Support\InputSiswaProcedure;
use App\Support\InputTagihanProcedure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Buat Tagihan Excel';
    public string $cacheKey = 'import_tagihan_excel';

    public ?string $sekolah = null;

    private function resolvedCacheKey(): string
    {
        return $this->cacheKey . ':' . (Auth::id() ?? 'guest');
    }

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
            }

            return $next($request);
        });
    }

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-data');
        $data['periode_otomatis'] = date('Ym');
        $data['periode_label'] = date('Y') . ' / ' . date('m');

        Cache::forget('import_tagihan_excel');
        Cache::forget($this->resolvedCacheKey());

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true],
            ['data' => 'gender', 'name' => 'Gender', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'angkatan', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true],
            ['data' => 'no_wa', 'name' => 'No WA', 'searchable' => true, 'orderable' => true],
            ['data' => 'nama_tagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
            ['data' => 'cicil', 'name' => 'Cicil', 'searchable' => true, 'orderable' => true, 'className' => 'text-center'],
            ['data' => 'exp_date', 'name' => 'ExpDate', 'searchable' => false, 'orderable' => false],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $cachedData = Cache::get($this->resolvedCacheKey(), []);
        $nisCount = count($cachedData);
        $previewExpDate = $this->resolvePreviewExpDate($request);

        $records = collect($cachedData)->map(function ($item) use ($previewExpDate) {
            return [
                'nis' => $item['nis'] ?? null,
                'name' => $item['nama'] ?? null,
                'gender' => $this->formatGenderLabel($item['gender'] ?? null),
                'unit' => $item['unit'] ?? null,
                'kelas' => $item['kelas'] ?? null,
                'kelompok' => $item['kelompok'] ?? null,
                'angkatan' => $item['angkatan'] ?? null,
                'no_wa' => $item['no_wa'] ?? null,
                'nama_tagihan' => $item['nama_tagihan'] ?? null,
                'nominal' => $item['nominal'] ?? null,
                'cicil' => $this->formatCicilLabel($item['cicil'] ?? null),
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
                'exp_date' => $previewExpDate,
            ];
        });

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => [
                    'required',
                    'file',
                    'mimes:xls,xlsx',
                    'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream',
                    'max:1024',
                ],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->file('fileImport');

        try {
            $requiredColumns = [
                'nis', 'nama', 'unit', 'kelas', 'kelompok', 'angkatan',
                'keterangan', 'nominal', 'cicil',
            ];
            $sheet = ExcelImportSheet::pickBest(
                $file->getRealPath(),
                $requiredColumns,
                'nis',
                [ExcelImportSheet::class, 'isTemplateSampleNis']
            );

            if ((int) ($sheet['usable'] ?? 0) === 0) {
                throw new \Exception('File hanya berisi baris contoh template (NIS 99999999…). Ganti dengan NIS siswa yang sebenarnya, hapus baris contoh, lalu import ulang.');
            }

            $cacheKey = $this->resolvedCacheKey();
            Cache::forget($cacheKey);
            Excel::import(new ImportTagihanExcel($cacheKey, $sheet['index']), $file);

            $data = Cache::get($cacheKey, []);
            if (empty($data)) {
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses. Pastikan file berisi NIS, KETERANGAN, dan Nominal.');
            }

            $invalidCount = collect($data)->where('status', 0)->count();
            $message = 'Sukses, data tagihan telah diimport, silahkan periksa kembali';
            if ($invalidCount > 0) {
                $message .= ". Ada {$invalidCount} baris bermasalah — perbaiki data sebelum menyimpan.";
            }

            Log::info('Upload tagihan excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'sheet_name' => $sheet['name'],
                'sheet_index' => $sheet['index'],
                'row_count' => count($data),
                'invalid_count' => $invalidCount,
            ]);

            return response()->json(['message' => $message, 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';

            Log::warning('Upload tagihan excel gagal validasi excel', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'errors' => $errorMessages,
            ]);

            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            Log::error('Upload tagihan excel gagal', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $error = $e->getMessage();

            return response()->json([
                'message' => "Gagal!<br> tidak dapat melakukan {$this->mainTitle}.<hr> {$error}",
                'error' => $error,
            ], 422);
        }
    }

    public function validateExcel(Request $request)
    {
        $request->validate([
            'exp_date' => ['nullable', 'date'],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->resolvedCacheKey());
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        $invalidCount = collect($data)->where('status', '!=', 1)->count();
        if ($invalidCount > 0) {
            return response()->json([
                'message' => "Ada {$invalidCount} baris bermasalah. Perbaiki data di kolom Keterangan terlebih dahulu, lalu upload ulang. Proses simpan ditolak.",
            ], 422);
        }

        $bta = date('Ym');

        try {
            EnsureImportSchoolClass::resetMemo();
            mst_thn_aka::resetEnsureMemo();

            $skippedInactive = [];
            $failed = [];
            $insertedCount = 0;
            $upsertedCust = 0;

            foreach ($data as $item) {
                if (($item['status'] ?? null) != 1) {
                    continue;
                }

                $nis = trim((string) ($item['nis'] ?? ''));
                $namaTagihan = trim((string) ($item['nama_tagihan'] ?? ''));
                if ($nis === '' || $namaTagihan === '') {
                    $failed[] = trim($nis . ' - data tidak lengkap');
                    continue;
                }

                $thnAka = mst_thn_aka::ensure((string) ($item['angkatan'] ?? ''));
                if (!$thnAka) {
                    return response()->json([
                        'message' => "ANGKATAN kosong/tidak valid untuk NIS {$nis}. Perbaiki data lalu upload ulang.",
                    ], 422);
                }

                [$sekolah, $kelas] = EnsureImportSchoolClass::resolve(
                    $item['unit'] ?? null,
                    $item['kelas'] ?? null,
                    $item['kelompok'] ?? null,
                );

                if (!$kelas || !$sekolah) {
                    return response()->json([
                        'message' => "Unit/kelas tidak dapat dibuat untuk NIS {$nis}. Periksa kolom Unit, Kelas, dan Kelompok.",
                    ], 422);
                }

                $siswa = $this->upsertCustomerFromImport($item, $sekolah, $kelas, $thnAka);
                $upsertedCust++;

                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim($nis . ' - ' . ($siswa->NMCUST ?? 'Tanpa Nama'));
                    continue;
                }

                $isNyicil = $this->resolveCicilFlag($item['cicil'] ?? null);
                if ($isNyicil === null) {
                    $failed[] = trim($nis . ' - CICIL harus 1 atau 0');
                    continue;
                }

                $nominal = (int) $item['nominal'];
                $nocust = (string) ($siswa->NOCUST ?? $siswa->nocust ?? $nis);
                $beforeAa = (int) (scctbill::where('CUSTID', $siswa->CUSTID)->max('AA') ?? 0);

                InputTagihanProcedure::call(
                    $nocust,
                    $nominal,
                    $namaTagihan,
                    $bta,
                    $bta,
                    $isNyicil,
                );

                $siswa = scctcust::where('NOCUST', $nis)->first() ?? $siswa;

                $newBill = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->where('AA', '>', $beforeAa)
                    ->orderByDesc('AA')
                    ->first();

                if (!$newBill) {
                    $failed[] = trim($nocust . ' - ' . ($siswa->NMCUST ?? ''));
                    continue;
                }

                $dirty = false;
                if ((int) ($newBill->isINSTALLABLE ?? 0) !== $isNyicil) {
                    $newBill->isINSTALLABLE = $isNyicil;
                    $dirty = true;
                }

                $vaCode = scctcust::vaBillCode($isNyicil);
                if ((string) ($newBill->VA ?? '') !== $vaCode) {
                    $newBill->VA = $vaCode;
                    $dirty = true;
                }

                if ((int) ($newBill->FSTSBolehBayar ?? 0) !== 1 || $newBill->BILLPAID === null) {
                    $newBill->FSTSBolehBayar = 1;
                    if ($newBill->BILLPAID === null) {
                        $newBill->BILLPAID = 0;
                    }
                    $dirty = true;
                }

                if ($request->filled('exp_date')) {
                    $newBill->ExpDate = date('Y-m-d 23:59:59', strtotime((string) $request->exp_date));
                    $dirty = true;
                }

                if ($dirty) {
                    $newBill->save();
                }

                $insertedCount++;
            }

            Cache::forget($this->resolvedCacheKey());
            Cache::increment('data_tagihan_cache_version');

            $message = "Data siswa & tagihan disimpan. Siswa di-upsert: {$upsertedCust}. Tagihan dibuat: {$insertedCount}. Periode: {$bta}.";
            if (!empty($skippedInactive)) {
                $message .= '<hr>Tagihan tidak dibuat untuk siswa nonaktif (STCUST=0): ' . count($skippedInactive) . ' siswa.<br>' .
                    implode('<br>', $skippedInactive);
            }
            if (!empty($failed)) {
                $message .= '<hr>Gagal dibuat (procedure tidak insert): ' . count($failed) . ' siswa.<br>' .
                    implode('<br>', $failed);
            }

            if ($insertedCount === 0 && empty($skippedInactive)) {
                return response()->json(['message' => $message ?: 'Tidak ada tagihan yang berhasil dibuat.'], 422);
            }

            return response()->json(['message' => $message], 200);
        } catch (\Throwable $e) {
            Log::error('Simpan tagihan excel gagal (InputTagihan)', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan data (siswa/tagihan).<hr>' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function upsertCustomerFromImport(
        array $item,
        mst_sekolah $sekolah,
        mst_kelas $kelas,
        mst_thn_aka $thnAka,
    ): scctcust {
        $nis = trim((string) ($item['nis'] ?? ''));
        $existing = scctcust::query()->where('NOCUST', $nis)->first();

        if (!$existing) {
            try {
                InputSiswaProcedure::call(
                    $nis,
                    (string) ($item['nama'] ?? ''),
                    $kelas,
                    $sekolah,
                    (string) ($item['angkatan'] ?? ''),
                    null,
                    isset($item['gender']) ? (string) $item['gender'] : null,
                    null,
                );
            } catch (\Throwable $procedureError) {
                Log::warning('upload_tagihan_excel.input_siswa_procedure_skipped', [
                    'nis' => $nis,
                    'message' => $procedureError->getMessage(),
                ]);
            }

            $existing = scctcust::query()->where('NOCUST', $nis)->first();
        }

        $payload = [
            'NOCUST' => $nis,
            'NMCUST' => $item['nama'] ?? ($existing->NMCUST ?? ''),
            'STCUST' => $existing ? (int) ($existing->STCUST ?? 1) : 1,
            'CODE01' => $sekolah->CODE01,
            'DESC01' => $sekolah->DESC01,
            'CODE02' => $kelas->unit,
            'DESC02' => $kelas->jenjang,
            'CODE03' => $kelas->id,
            'DESC03' => $kelas->kelas,
            'DESC04' => $thnAka->thn_aka,
            'LastUpdate' => Carbon::now(),
        ];

        if (array_key_exists('gender', $item) && $item['gender'] !== null && $item['gender'] !== '') {
            $payload['CODE04'] = (string) $item['gender'];
        }

        $noWa = $this->normalizeNoWa($item['no_wa'] ?? null);
        if ($noWa !== null) {
            $payload['NO_WA'] = $noWa;
        }

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh() ?? $existing;
        }

        $payload['CUSTID'] = scctcust::nextCustId();
        $payload['NUM2ND'] = $item['nodaftar'] ?? '-';
        $payload['STCUST'] = 1;

        return scctcust::create($payload);
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
     * Preview ExpDate: dari form jika diisi, selain itu ikuti logic InputTagihan (tgl 20).
     */
    private function resolvePreviewExpDate(Request $request): string
    {
        $fromForm = $request->input('exp_date') ?? $request->input('filter.exp_date');
        if (filled($fromForm)) {
            try {
                return Carbon::parse($fromForm)->format('d-m-Y');
            } catch (\Throwable) {
                // fallback otomatis
            }
        }

        return InputTagihanProcedure::resolveAutoExpDate()->format('d-m-Y');
    }

    /**
     * CICIL dari Excel saja (1/0).
     */
    private function resolveCicilFlag(mixed $value): ?int
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

    private function formatCicilLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? '1 (Ya)' : '0 (Tidak)';
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'ya', 'yes', 'true', 'y'], true) || (is_numeric($value) && (int) $value === 1)) {
            return '1 (Ya)';
        }
        if (in_array($normalized, ['0', 'tidak', 'no', 'false', 'n'], true) || (is_numeric($value) && (int) $value === 0)) {
            return '0 (Tidak)';
        }

        return (string) $value;
    }

    private function formatGenderLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === 'L') {
            return 'L';
        }
        if ($normalized === 'P') {
            return 'P';
        }

        return (string) $value;
    }
}
