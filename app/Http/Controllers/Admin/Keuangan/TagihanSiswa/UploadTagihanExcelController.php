<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\ExcelImportSheet;
use App\Support\InputTagihanProcedure;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
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

        $currentYear = (int) date('Y');
        $data['periode_tahun_list'] = range($currentYear - 2, $currentYear + 5);
        $data['periode_tahun_default'] = $currentYear;
        $data['periode_bulan_default'] = (int) date('m');
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

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
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
            ['data' => 'cicil', 'name' => 'Cicil', 'searchable' => true, 'orderable' => true, 'className' => 'text-center'],
            ['data' => 'exp_date', 'name' => 'ExpDate', 'searchable' => false, 'orderable' => false],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'scctcust.nocust';
        $defaultOrder = 'asc';

        $columnSortOrder = $defaultOrder;
        $columnName = $defaultColumn;

        if ($request->has('order')) {
            $order = $request->get('order');
            $columnIndex = (int) ($order[0]['column'] ?? 0);
            $columnSortOrder = $order[0]['dir'] ?? $defaultOrder;
            $requestedColumn = $columnName_arr[$columnIndex]['data'] ?? null;

            if ($requestedColumn && $requestedColumn !== 'no') {
                $columnName = 'scctcust.' . $requestedColumn;
            }
        }

        $searchValue = $search_arr['value'] ?? '';

        $filters = [];
        $filterQuery = null;

        $cachedData = Cache::get($this->resolvedCacheKey(), []);

        $nisList = collect($cachedData)->pluck('nis')->toArray();
        $nisCount = count($cachedData);


        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.NUM2ND',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',
        ]));

        $previewExpDate = $this->resolvePreviewExpDate($request);

        $records = collect($cachedData)->map(function ($item) use ($select, $previewExpDate) {
            $nis = $item['nis'];
            $siswa = scctcust::select($select)->where('scctcust.NOCUST', $nis);
            SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
            $siswa = $siswa->first();
            return [
                'nis' => $nis,
                'name' => $siswa->NMCUST ?? null,
                'ortu' => $item['ayah'] ?? null,
                'unit' => $siswa->CODE02 ?? null,
                'kelas' => $siswa->DESC02 ?? null,
                'kelompok' => $siswa->DESC03 ?? null,
                'nominal' => $item['nominal'] ?? null,
                'cicil' => $this->formatCicilLabel($item['cicil'] ?? null),
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'],
                'exp_date' => $previewExpDate,
            ];
        });

        $response = array(
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        );
        return response()->json($response);
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
            $requiredColumns = ['nis', 'nama', 'unit', 'kelas', 'kelompok', 'angkatan', 'nominal', 'cicil'];
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
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses. Pastikan file berisi NIS dan Nominal.');
            }

            Log::info('Upload tagihan excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'sheet_name' => $sheet['name'],
                'sheet_index' => $sheet['index'],
                'row_count' => count($data),
            ]);

            return response()->json(['message' => 'Sukses, data tagihan telah diimport, silahkan periksa kembali', 'data' => $data], 200);
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
            'tagihan' => ['required'],
            'periode_tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
            'periode_bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'exp_date' => ['nullable', 'date'],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->resolvedCacheKey());
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        $bta = sprintf('%04d%02d', (int) $request->periode_tahun, (int) $request->periode_bulan);

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) {
            return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);
        }

        $nmTagihan = trim((string) $tagihan->tagihan);

        try {
            $skippedInactive = [];
            $failed = [];
            $insertedCount = 0;

            foreach ($data as $item) {
                if (($item['status'] ?? null) != 1) {
                    continue;
                }

                $siswaQuery = scctcust::where('NOCUST', $item['nis']);
                SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);
                $siswa = $siswaQuery->first();

                if (!$siswa) {
                    return response()->json(['message' => "siswa dengan nis: {$item['nis']} tidak ditemukan!"], 422);
                }
                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim(($item['nis'] ?? '-') . ' - ' . ($siswa->NMCUST ?? 'Tanpa Nama'));
                    continue;
                }

                $isNyicil = $this->resolveCicilFlag($item['cicil'] ?? null);
                if ($isNyicil === null) {
                    $failed[] = trim(($item['nis'] ?? '-') . ' - CICIL harus 1 atau 0 (ikuti Excel, bukan master tagihan)');
                    continue;
                }

                $nominal = (int) $item['nominal'];
                $nocust = (string) ($siswa->NOCUST ?? $siswa->nocust ?? $item['nis']);
                $beforeAa = (int) (scctbill::where('CUSTID', $siswa->CUSTID)->max('AA') ?? 0);

                // ExpDate diisi otomatis oleh procedure InputTagihan
                // p_isNYICIL mengikuti kolom CICIL di Excel (bukan master tagihan)
                InputTagihanProcedure::call(
                    $nocust,
                    $nominal,
                    $nmTagihan,
                    $bta,
                    $bta,
                    $isNyicil,
                );

                $newBill = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->where('AA', '>', $beforeAa)
                    ->orderByDesc('AA')
                    ->first();

                if (!$newBill) {
                    $failed[] = trim($nocust . ' - ' . ($siswa->NMCUST ?? ''));
                    continue;
                }

                // Paksa flag cicil + VA mengikuti Excel (bukan master tagihan)
                $dirty = false;
                if ((int) ($newBill->isINSTALLABLE ?? 0) !== $isNyicil) {
                    $newBill->isINSTALLABLE = $isNyicil;
                    $dirty = true;
                }

                // Kolom scctbill.VA pendek (89/90) — jangan isi nomor VA 16 digit
                $vaCode = scctcust::vaBillCode($isNyicil);
                if ((string) ($newBill->VA ?? '') !== $vaCode) {
                    $newBill->VA = $vaCode;
                    $dirty = true;
                }

                // Pastikan tampil di Data Tagihan (procedure tidak set FSTSBolehBayar)
                if ((int) ($newBill->FSTSBolehBayar ?? 0) !== 1 || $newBill->BILLPAID === null) {
                    $newBill->FSTSBolehBayar = 1;
                    if ($newBill->BILLPAID === null) {
                        $newBill->BILLPAID = 0;
                    }
                    $dirty = true;
                }

                // Opsional: override ExpDate dari form jika diisi
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

            $message = "Data tagihan disimpan via InputTagihan. Berhasil dibuat untuk {$insertedCount} siswa.";
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
                'message' => 'Terjadi kesalahan saat menyimpan data (InputTagihan).<hr>' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Preview ExpDate: dari form jika diisi, selain itu ikuti logic InputTagihan (tgl 20).
     */
    private function resolvePreviewExpDate(Request $request): string
    {
        $fromForm = $request->input('exp_date') ?? $request->input('filter.exp_date');
        if (filled($fromForm)) {
            try {
                return \Illuminate\Support\Carbon::parse($fromForm)->format('d-m-Y');
            } catch (\Throwable) {
                // fallback otomatis
            }
        }

        return InputTagihanProcedure::resolveAutoExpDate()->format('d-m-Y');
    }

    /**
     * CICIL dari Excel saja (1/0). Tidak mengambil dari master tagihan.
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
}
