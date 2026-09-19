<?php

namespace App\Http\Controllers\Admin\Keuangan\Saldo;

use App\Exports\SaldoVirtualAccountDetailExport;
use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_sekolah;
use App\Models\mst_thn_aka;
use App\Models\scctcust;
use App\Models\sccttran;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Mockery\Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SaldoVirtualAccountController extends Controller
{
    public ?string $sekolah = null;
    public string $datasUrl = '';
    public string $detailDatasUrl = '';
    public string $columnsUrl = '';
    private string $title = "Saldo";
    private string $mainTitle = 'Saldo Virtual Account';
    private string $dataTitle = 'Saldo Virtual Account';
    private string $showTitle = 'Detail Saldo  Virtual Account';
    private string $cacheKey = 'saldo_virtual_account';

    /** Pembayaran manual cash — tidak masuk saldo/jurnal VA. */
    private const FIDBANK_MANUAL_CASH = '1140000';

    /** Tampilkan semua transaksi sccttran kecuali manual cash (1140000). */
    private function excludeManualCashScope($query, string $fidBankColumn = 'FIDBANK')
    {
        return $query->where(function ($q) use ($fidBankColumn) {
            $q->whereNull($fidBankColumn)
                ->orWhereRaw("TRIM(COALESCE(CAST({$fidBankColumn} AS CHAR), '')) = ''")
                ->orWhereRaw("TRIM(COALESCE(CAST({$fidBankColumn} AS CHAR), '')) != ?", [self::FIDBANK_MANUAL_CASH]);
        });
    }

    private array $allowedFilters = [
        'kelas' => 'scctcust.DESC02',
        'sekolah' => 'scctcust.CODE01',
        'siswa' => 'scctcust.nmcust',
        'angkatan' => 'scctcust.DESC04',
    ];

    private function resolveScopedSchoolCodes(): array
    {
        if (blank($this->sekolah)) {
            return [];
        }

        return [trim((string) $this->sekolah)];
    }

    private function applyFilterQuery($query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (($filter[0] ?? null) === 'whereRaw') {
                $query->whereRaw($filter[1], $filter[2] ?? []);
                continue;
            }
            if (count($filter) === 3) {
                if (($filter[1] ?? null) === 'in' && is_array($filter[2] ?? null)) {
                    $query->whereIn($filter[0], $filter[2]);
                } else {
                    $query->where($filter[0], $filter[1], $filter[2]);
                }
            } elseif (count($filter) === 4) {
                if ($filter[3] == 'whereBetween') {
                    $query->whereBetween($filter[0], [$filter[1], $filter[2]]);
                } else {
                    $query->{$filter[3]}($filter[0], $filter[1], $filter[2]);
                }
            }
        }
    }

    /**
     * Cari siswa by NIS / No VA / no daftar / nama.
     * NIS di DB sering berpadding nol atau tersimpan numerik, jadi dicocokkan setelah TRIM/CAST.
     */
    private function applySiswaLookup($query, string $input, bool $asGroup = true): void
    {
        $input = trim($input);
        if ($input === '') {
            return;
        }

        $sanitize = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $input);
        $like = '%' . $sanitize . '%';
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        $prefixes = array_values(array_unique(array_filter([
            scctcust::vaPrefixClose(),
            scctcust::vaPrefixOpen(),
            scctcust::vaPrefix(),
        ])));

        $nisCandidates = [];
        if ($digits !== '') {
            $nisCandidates[] = $digits;
            $nisCandidates[] = ltrim($digits, '0') ?: '0';

            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix)) {
                    $fromVa = substr($digits, strlen($prefix));
                    $nisCandidates[] = $fromVa;
                    $nisCandidates[] = ltrim($fromVa, '0') ?: '0';
                }
            }
        }
        $nisCandidates = array_values(array_unique(array_filter($nisCandidates, static fn ($v) => $v !== '')));

        $apply = function ($q) use ($like, $digits, $nisCandidates) {
            $q->orWhereRaw('TRIM(CAST(scctcust.NOCUST AS CHAR)) LIKE ?', [$like])
                ->orWhereRaw('TRIM(CAST(scctcust.NUM2ND AS CHAR)) LIKE ?', [$like])
                ->orWhere('scctcust.NMCUST', 'like', $like);

            if ($digits !== '') {
                $q->orWhereRaw('TRIM(CAST(scctcust.NOCUST AS CHAR)) LIKE ?', ['%' . $digits . '%'])
                    ->orWhereRaw('TRIM(CAST(scctcust.NUM2ND AS CHAR)) LIKE ?', ['%' . $digits . '%']);
            }

            foreach ($nisCandidates as $nis) {
                $q->orWhereRaw('TRIM(CAST(scctcust.NOCUST AS CHAR)) = ?', [$nis])
                    ->orWhereRaw("TRIM(LEADING '0' FROM TRIM(CAST(scctcust.NOCUST AS CHAR))) = ?", [ltrim($nis, '0') ?: '0'])
                    ->orWhereRaw('TRIM(CAST(scctcust.NUM2ND AS CHAR)) = ?', [$nis]);
            }
        };

        if ($asGroup) {
            $query->where($apply);
            return;
        }

        $apply($query);
    }

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
            }
            return $next($request);
        });

        $this->title = 'Keuangan';
        $this->mainTitle = 'Saldo';
        $this->dataTitle = 'Saldo Virtual Account';
        $this->showTitle = 'Detail Saldo  Virtual Account';


        $this->datasUrl = route('admin.keuangan.saldo.saldo-virtual-account.get-data');
        $this->detailDatasUrl = '';
        $this->columnsUrl = route('admin.keuangan.saldo.saldo-virtual-account.get-column');
    }

    public function index()
    {
        $schoolCodes = $this->resolveScopedSchoolCodes();

        $data['thn_aka'] = mst_thn_aka::getMstThnAkaAttributes();
        $data['sekolah'] = mst_sekolah::select(['CODE01', 'DESC01'])
            ->when(!empty($schoolCodes), function ($query) use ($schoolCodes) {
                $query->whereIn('CODE01', $schoolCodes);
            })
            ->orderBy('DESC01')
            ->get();
        $data['kelas'] = mst_kelas::dropdownQuery($this->sekolah)
            ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
            ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
            ->get();
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        //        $data['showTitle'] = $this->showTitle;
        $data['columnsUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.get-column');
        $data['datasUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.get-data');
        $data['dataTransaksiUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.data-transaksi.index');

        return view('admin.keuangan.saldo.saldo_virtual_account.index', $data);
    }

    public function show($id)
    {
        try {
            $data['title'] = $this->title;
            $data['mainTitle'] = $this->mainTitle;
            $data['dataTitle'] = $this->dataTitle;
            $data['showTitle'] = $this->showTitle;
            $data['indexUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.index');
            $data['columnsUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.transaksi.get-column');
            $data['datasUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.transaksi.get-data', ['CUSTID' => $id]);
            $data['exportTransaksiUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.export', ['id' => $id]);

            $data['siswa'] = scctcust::find($id);

            if ($data['siswa']) {
                if ($data['siswa']->NOCUST && $data['siswa']->NOCUST != '-') {
                    $NOVA = scctcust::showVA($data['siswa']->NOCUST);
                } else {
                    $NOVA = scctcust::showVA($data['siswa']->NUM2ND);
                }
                $data['siswa']->NOVA = $NOVA;

                $data['totalKredit'] = (int) $this->excludeManualCashScope(sccttran::query())
                    ->where('CUSTID', $id)
                    ->sum('KREDIT');
                $data['totalDebet'] = (int) $this->excludeManualCashScope(sccttran::query())
                    ->where('CUSTID', $id)
                    ->sum('DEBET');
//                $data['siswa']-> = $NOVA;
            } else {
                throw new Exception('Siswa tidak ditemukan');
            }

            return view('admin.keuangan.saldo.saldo_virtual_account.show', $data);
        } catch (\Exception $e) {
            return redirect()->route('admin.keuangan.saldo.saldo-virtual-account.index')->with('error', 'Siswa tidak ditemukan!');
        }
    }

    public function exportTransaksi(Request $request): BinaryFileResponse
    {
        $siswaInput = trim((string) $request->query('siswa', ''));
        if ($siswaInput === '') {
            abort(422, 'Masukkan NIS/Nama siswa di filter terlebih dahulu.');
        }

        $siswa = $this->resolveCustFromSiswaInput($siswaInput);
        if (!$siswa) {
            abort(404, 'Siswa tidak ditemukan.');
        }

        return $this->exportDetail($siswa->CUSTID);
    }

    public function exportDetail($id): BinaryFileResponse
    {
        $siswa = scctcust::query()->where('CUSTID', $id)->first();
        if (!$siswa) {
            abort(404, 'Siswa tidak ditemukan');
        }

        $transactions = $this->getCustTransactions($id);
        $totalKredit = (int) $transactions->sum('KREDIT');
        $totalDebet = (int) $transactions->sum('DEBET');
        $saldo = $totalKredit - $totalDebet;

        if ($siswa->NOCUST && $siswa->NOCUST != '-') {
            $nova = scctcust::showVA($siswa->NOCUST);
        } else {
            $nova = scctcust::showVA($siswa->NUM2ND);
        }

        $nis = preg_replace('/\D/', '', (string) ($siswa->NOCUST ?? $siswa->nocust ?? $siswa->CUSTID));
        $filename = 'transaksi-saldo-va-' . ($nis !== '' ? $nis : $siswa->CUSTID) . '-' . date('Ymd-His') . '.xlsx';

        return Excel::download(
            new SaldoVirtualAccountDetailExport(
                [
                    'nis' => (string) ($siswa->NOCUST ?? $siswa->nocust ?? '-'),
                    'nama' => (string) ($siswa->NMCUST ?? $siswa->nmcust ?? '-'),
                    'unit' => (string) ($siswa->CODE02 ?? '-'),
                    'kelas' => (string) ($siswa->DESC02 ?? '-'),
                    'kelompok' => (string) ($siswa->DESC03 ?? '-'),
                    'nova' => (string) ($nova ?? '-'),
                ],
                $transactions,
                $totalDebet,
                $totalKredit,
                $saldo,
            ),
            $filename
        );
    }

    private function resolveCustFromSiswaInput(string $input): ?scctcust
    {
        $query = scctcust::query();
        $scopedCodes = $this->resolveScopedSchoolCodes();
        if (!empty($scopedCodes)) {
            $query->whereIn('CODE01', $scopedCodes);
        }

        if (ctype_digit($input)) {
            $byNis = (clone $query)->where('NOCUST', $input)->first();
            if ($byNis) {
                return $byNis;
            }

            return (clone $query)->where('CUSTID', $input)->first();
        }

        $matches = (clone $query)
            ->where('NMCUST', 'like', '%' . $input . '%')
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            abort(422, 'Data siswa tidak unik. Gunakan NIS untuk export transaksi.');
        }

        return null;
    }

    private function getCustTransactions(string|int $custId)
    {
        return $this->excludeManualCashScope(
            sccttran::query()->where('CUSTID', $custId),
            'FIDBANK'
        )
            ->orderBy('TRXDATE', 'desc')
            ->get(['METODE', 'TRXDATE', 'DEBET', 'KREDIT', 'NOREFF', 'TRANSNO']);
    }

    public function getColumn(Request $request)
    {
        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'JENIS_VA', 'name' => 'Jenis VA', 'searchable' => false, 'orderable' => false, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'NO VA', 'exportable' => true],
            ['data' => 'NMCUST', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'CODE02', 'name' => 'Unit', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC02', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC03', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NUM2ND', 'name' => 'No Pendaftaran', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC04', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'saldo', 'name' => 'Saldo', 'orderable' => true, 'columnType' => 'currency', 'className' => 'text-end', 'exportable' => true],
            [
                'data' => 'print',
                'name' => '',
                'columnType' => 'button',
                'className' => 'text-center',
                'button' => 'link',
                'buttonLink' => route('admin.keuangan.saldo.saldo-virtual-account.show', ':id'),
                'buttonText' => 'Detail Transaksi',
                'noCaption' => true,
                'buttonClass' => 'btn btn-sm btn-primary btn-icon btn-print-tagihan',
                'buttonIcon' => 'ri-profile-line',
                'exportable' => false,
            ],
        ];
    }

    public function getData(Request $request)
    {
        $filters = [];
        $filterQuery = null;

        $draw = $request->get('draw');
        $start = $request->get("start");
        $rowperpage = $request->get("length");

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'scctcust.NOCUST';
        $defaultOrder = 'asc';

        if ($request->has('order') && !empty($request->get('order'))) {
            $columnIndex_arr = $request->get('order');
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0;
            $columnSortOrder = $columnIndex_arr[0]['dir'] ?? $defaultOrder;
            $columnName = $columnName_arr[$columnIndex]['data'] ?? $defaultColumn;
        } else {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $searchValue = $search_arr['value'] ?? '';

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        if ($columnName === 'saldo') {
            $columnName = 'saldo';
        } elseif (!str_contains($columnName, '.')) {
            $columnName = 'scctcust.' . $columnName;
        }

        $siswaFilter = '';
        $vaTypeFilter = 'all';
        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (strtolower((string) $val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        'kelas' => 'scctcust.DESC02',
                        'sekolah' => 'scctcust.CODE01',
                        'siswa' => 'scctcust.NOCUST',
                        'angkatan' => 'scctcust.DESC04',
                        'saldo_positif' => '_saldo_positif',
                        'va_type' => '_va_type',
                        default => null
                    };
                    if ($key == 'siswa') {
                        $siswaFilter = trim((string) $val);
                    } else if ($key == 'kelas') {
                        $filters[] = ['scctcust.CODE03', '=', $val];
                    } else if ($key === 'sekolah') {
                        $filters[] = ['scctcust.CODE01', '=', trim((string) $val)];
                    } else if ($key === 'va_type') {
                        $vaTypeFilter = strtolower(trim((string) $val));
                    } else if ($key == 'saldo_positif') {
                        if ((string) $val === '1') {
                            $filters[] = ['whereRaw', '(COALESCE(trx_close.kredit, 0) - COALESCE(trx_close.debet, 0) + COALESCE(trx_open.kredit, 0) - COALESCE(trx_open.debet, 0)) > 0', []];
                        }
                    } else {
                        ($colName) && $filters[] = [$colName, '=', $val];
                    }
                }
            }

            $scopedCodes = $this->resolveScopedSchoolCodes();
            if (!empty($scopedCodes)) {
                $filters[] = ['scctcust.CODE01', 'in', $scopedCodes];
            }

            if (!empty($filters)) {
                $filterQuery = fn($query) => $this->applyFilterQuery($query, $filters);
            }
        } else {
            $scopedCodes = $this->resolveScopedSchoolCodes();
            if (!empty($scopedCodes)) {
                $filters[] = ['scctcust.CODE01', 'in', $scopedCodes];
                $filterQuery = fn($query) => $this->applyFilterQuery($query, $filters);
            }
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NUM2ND',
        ];

        $select = array_unique(array_merge($whereAny, [
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.CUSTID',
            'scctcust.DESC04',
        ]));

        $prefixClose = scctcust::vaPrefixClose();
        $prefixOpen = scctcust::vaPrefixOpen();

        $saldoAggClose = $this->excludeManualCashScope(sccttran::query())
            ->whereRaw("TRIM(COALESCE(CAST(REFFBANK AS CHAR), '')) LIKE ?", [$prefixClose . '%'])
            ->select([
                'CUSTID',
                DB::raw('COALESCE(SUM(KREDIT), 0) AS kredit'),
                DB::raw('COALESCE(SUM(DEBET), 0) AS debet'),
            ])
            ->groupBy('CUSTID');

        $saldoAggOpen = $this->excludeManualCashScope(sccttran::query())
            ->whereRaw("TRIM(COALESCE(CAST(REFFBANK AS CHAR), '')) LIKE ?", [$prefixOpen . '%'])
            ->select([
                'CUSTID',
                DB::raw('COALESCE(SUM(KREDIT), 0) AS kredit'),
                DB::raw('COALESCE(SUM(DEBET), 0) AS debet'),
            ])
            ->groupBy('CUSTID');

        $query = scctcust::query()
            ->leftJoinSub($saldoAggClose, 'trx_close', function ($join) {
                $join->on('trx_close.CUSTID', '=', 'scctcust.CUSTID');
            })
            ->leftJoinSub($saldoAggOpen, 'trx_open', function ($join) {
                $join->on('trx_open.CUSTID', '=', 'scctcust.CUSTID');
            });

        if ($filterQuery) {
            $query->where(function ($q) use ($filterQuery) {
                $filterQuery($q);
            });
        }

        if ($siswaFilter !== '') {
            $this->applySiswaLookup($query, $siswaFilter);
        }

        if (!blank($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $this->applySiswaLookup($q, $searchValue, false);
            });
        }

        $scopedCodesForCount = $this->resolveScopedSchoolCodes();
        $totalRecords = Cache::remember('scctcust_total_count_' . md5(json_encode($scopedCodesForCount)), 600, function () use ($scopedCodesForCount) {
            return scctcust::when(!empty($scopedCodesForCount), function ($query) use ($scopedCodesForCount) {
                $query->whereIn('CODE01', $scopedCodesForCount);
            })->count('CUSTID');
        });

        $totalRecordswithFilter = (clone $query)->count('scctcust.CUSTID');

        // Ambil siswa dulu; tiap siswa dipecah jadi baris Close dan/atau Open
        $pageStudents = (clone $query)
            ->select($select)
            ->addSelect([
                DB::raw('COALESCE(trx_close.kredit, 0) AS kredit_close'),
                DB::raw('COALESCE(trx_close.debet, 0) AS debet_close'),
                DB::raw('(COALESCE(trx_close.kredit, 0) - COALESCE(trx_close.debet, 0)) AS saldo_close'),
                DB::raw('COALESCE(trx_open.kredit, 0) AS kredit_open'),
                DB::raw('COALESCE(trx_open.debet, 0) AS debet_open'),
                DB::raw('(COALESCE(trx_open.kredit, 0) - COALESCE(trx_open.debet, 0)) AS saldo_open'),
            ])
            ->orderBy($columnName === 'saldo'
                ? DB::raw('(COALESCE(trx_close.kredit, 0) - COALESCE(trx_close.debet, 0) + COALESCE(trx_open.kredit, 0) - COALESCE(trx_open.debet, 0))')
                : $columnName, $columnSortOrder)
            ->skip($start)
            ->take($rowperpage)
            ->get();

        $vaModes = match ($vaTypeFilter) {
            'open', '1', 'cicil' => [[1, 'Open (Cicil)', 'saldo_open']],
            'close', '0' => [[0, 'Close', 'saldo_close']],
            default => [
                [0, 'Close', 'saldo_close'],
                [1, 'Open (Cicil)', 'saldo_open'],
            ],
        };

        $records = [];
        foreach ($pageStudents as $item) {
            $nis = ($item->NOCUST && $item->NOCUST != '-') ? $item->NOCUST : $item->NUM2ND;
            foreach ($vaModes as [$flag, $label, $saldoKey]) {
                $row = $item->replicate();
                $row->item_id = $item->CUSTID;
                $row->print = true;
                $row->JENIS_VA = $label;
                $row->va_type = $flag === 1 ? 'open' : 'close';
                $row->NOVA = scctcust::showVA($nis, $flag);
                $row->saldo = (int) ($item->{$saldoKey} ?? 0);
                $row->kredit = (int) ($flag === 1 ? $item->kredit_open : $item->kredit_close);
                $row->debet = (int) ($flag === 1 ? $item->debet_open : $item->debet_close);
                unset($row->CUSTID);
                $records[] = $row->toArray();
            }
        }

        // Count baris tampilan (siswa x jenis VA)
        $multiplier = count($vaModes);
        $response = array(
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords * $multiplier,
            "recordsFiltered" => $totalRecordswithFilter * $multiplier,
            "data" => $records,
        );
        return response()->json($response);
    }

    public function getColumnTran()
    {
        return [
            ['data' => null, 'columnType' => 'row', 'name' => 'No', 'exportable' => true],
            ['data' => 'JENIS_VA', 'name' => 'Jenis VA', 'orderable' => false, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'No VA', 'orderable' => false, 'exportable' => true],
            ['data' => 'METODE', 'name' => 'Metode', 'orderable' => true, 'exportable' => true],
            ['data' => 'TRXDATE', 'name' => 'Tanggal Transaksi', 'orderable' => true, 'columnType' => 'timestamp', 'exportable' => true],
            ['data' => 'DEBET', 'name' => 'Debet', 'orderable' => true, 'className' => 'dt-right', 'columnType' => 'currency', 'exportable' => true],
            ['data' => 'KREDIT', 'name' => 'Kredit', 'orderable' => true, 'className' => 'dt-right', 'columnType' => 'currency', 'exportable' => true],
            ['data' => 'NOREFF', 'name' => 'No Ref', 'orderable' => true, 'exportable' => true],
            ['data' => 'TRANSNO', 'name' => 'Trans No', 'orderable' => true, 'exportable' => true],
        ];
    }

    public function getDataTran(Request $request)
    {
        $custid = $request->input('CUSTID');
        $filters = [];

        $draw = $request->get('draw');
        $start = $request->get("start");
        $rowperpage = $request->get("length");

        $columnName_arr = $request->get('columns');
        $search_arr = $request->get('search');

        $defaultColumn = 'sccttran.TRXDATE';
        $defaultOrder = 'desc';

        if ($request->has('order') && !empty($request->get('order'))) {
            $columnIndex_arr = $request->get('order');
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0;
            $columnSortOrder = $columnIndex_arr[0]['dir'] ?? $defaultOrder;
            $columnName = $columnName_arr[$columnIndex]['data'] ?? $defaultColumn;
        } else {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        $searchValue = $search_arr['value'] ?? '';

        if (!$columnName || $columnName == 'no') {
            $columnName = $defaultColumn;
            $columnSortOrder = $defaultOrder;
        }

        if (!str_contains($columnName, '.')) {
            $columnName = 'sccttran.' . $columnName;
        }

        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (strtolower($val) != 'all' && $val !== null && $val !== '') {
                    $colName = match ($key) {
                        'status' => 'scctbill.PAIDST',
                        'jenis' => 'scctbill.cicil',
                        'kelas' => 'mst_siswas.id_kelas',
                        'tahun_akademik' => 'mst_siswas.id_thn_aka',
                        default => null
                    };
                    ($colName) && $filters[] = [$colName, '=', $val];
                }
            }
        }

        if ($custid) {
            $filters[] = ['sccttran.CUSTID', '=', $custid];
        }

        $whereAny = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NUM2ND',
            'sccttran.METODE',
        ];

        $select = array_merge($whereAny, [
            'sccttran.METODE',
            'sccttran.TRXDATE',
            'sccttran.NOREFF',
            'sccttran.FIDBANK',
            'sccttran.KDCHANNEL',
            'sccttran.DEBET',
            'sccttran.KREDIT',
            'sccttran.REFFBANK',
            'sccttran.TRANSNO',
        ]);

        $query = $this->excludeManualCashScope(
            sccttran::query()->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID'),
            'sccttran.FIDBANK'
        );

        if (!empty($filters)) {
            $this->applyFilterQuery($query, $filters);
        }

        if (!blank($searchValue)) {
            $query->where(function ($q) use ($whereAny, $searchValue) {
                $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
                foreach ($whereAny as $column) {
                    $q->orWhere($column, 'like', '%' . $sanitizeSearch . '%');
                }
            });
        }

        $totalRecords = $this->excludeManualCashScope(sccttran::query())
            ->when($custid, fn ($q) => $q->where('CUSTID', $custid))
            ->count();

        $totalRecordswithFilter = (clone $query)->count();

        $records = (clone $query)
            ->orderBy($columnName, $columnSortOrder)
            ->select($select)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) {
                unset($item->id);
                $flag = scctcust::resolveInstallableFromReffBank($item->REFFBANK ?? null);
                $item->JENIS_VA = $flag === null ? '-' : scctcust::vaTypeLabel($flag);
                $reff = preg_replace('/\D/', '', (string) ($item->REFFBANK ?? ''));
                if (strlen($reff) >= 12) {
                    $item->NOVA = $reff;
                } else {
                    $nis = ($item->NOCUST && $item->NOCUST != '-') ? $item->NOCUST : ($item->NUM2ND ?? '');
                    $item->NOVA = $flag === null ? scctcust::showVA($nis) : scctcust::showVA($nis, $flag);
                }

                return $item;
            })
            ->toArray();

        $totalKredit = 0;
        $totalDebet = 0;

        if ($custid) {
            $totalKredit = Cache::remember(
                "total_kredit_va_custid_" . $custid,
                600,
                fn () => (int) $this->excludeManualCashScope(sccttran::query())
                    ->where('CUSTID', $custid)
                    ->sum('KREDIT')
            );

            $totalDebet = Cache::remember(
                "total_debet_va_custid_" . $custid,
                600,
                fn () => (int) $this->excludeManualCashScope(sccttran::query())
                    ->where('CUSTID', $custid)
                    ->sum('DEBET')
            );
        }

        $response = [
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords,
            "recordsFiltered" => $totalRecordswithFilter,
            "data" => $records,
        ];

        if ($custid) {
            $response['totals'] = [
                'kredit' => ['location' => 4, 'value' => $totalKredit, 'columnType' => 'currency'],
                'debet' => ['location' => 3, 'value' => $totalDebet, 'columnType' => 'currency'],
            ];
        }

        return response()->json($response);
    }

    public function resolveCustSaldo(string|int|null $custId): int
    {
        if (blank($custId)) {
            return 0;
        }

        return (int) sccttran::query()
            ->where('CUSTID', $custId)
            ->selectRaw('COALESCE(SUM(KREDIT), 0) - COALESCE(SUM(DEBET), 0) AS saldo')
            ->value('saldo');
    }

    public function getSaldo(Request $request)
    {
        return response()->json([
            'saldo' => $this->resolveCustSaldo($request->input('siswa')),
        ]);
    }

    public function transaksiIndex()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->dataTitle;
        $data['pageTitle'] = 'Data Transaksi';
        $data['columnsUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.data-transaksi.get-column');
        $data['datasUrl'] = route('admin.keuangan.saldo.saldo-virtual-account.data-transaksi.get-data');
        $data['prefillSiswa'] = trim((string) request()->query('siswa', request()->query('nis', '')));

        return view('admin.keuangan.saldo.saldo_virtual_account.data_transaksi', $data);
    }

    public function getColumnDataTransaksi()
    {
        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'JENIS_VA', 'name' => 'Jenis VA', 'searchable' => false, 'orderable' => false, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'No VA', 'searchable' => true, 'exportable' => true],
            ['data' => 'NMCUST', 'name' => 'Nama', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'TRXDATE', 'name' => 'Tanggal Bayar', 'orderable' => true, 'columnType' => 'timestamp', 'exportable' => true],
            ['data' => 'METODE', 'name' => 'Metode', 'orderable' => true, 'exportable' => true],
            ['data' => 'DEBET', 'name' => 'Debet', 'orderable' => true, 'className' => 'text-end', 'columnType' => 'currency', 'exportable' => true],
            ['data' => 'KREDIT', 'name' => 'Kredit', 'orderable' => true, 'className' => 'text-end', 'columnType' => 'currency', 'exportable' => true],
            ['data' => 'NOREFF', 'name' => 'No Ref', 'orderable' => true, 'exportable' => true],
            ['data' => 'CODE02', 'name' => 'Unit', 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC02', 'name' => 'Kelas', 'orderable' => true, 'exportable' => true],
            ['data' => 'DESC03', 'name' => 'Kelompok', 'orderable' => true, 'exportable' => true],
        ];
    }

    public function getDataDataTransaksi(Request $request)
    {
        $filters = [];

        $draw = (int) $request->get('draw');
        $start = (int) $request->get('start', 0);
        $rowperpage = (int) $request->get('length', 25);

        $columnName_arr = $request->get('columns', []);
        $search_arr = $request->get('search', []);
        $searchValue = $search_arr['value'] ?? '';

        $defaultColumn = 'sccttran.TRXDATE';
        $defaultOrder = 'desc';
        $columnName = $defaultColumn;
        $columnSortOrder = $defaultOrder;

        if ($request->has('order') && !empty($request->get('order'))) {
            $order_arr = $request->get('order');
            $columnIndex = $order_arr[0]['column'] ?? 0;
            $columnSortOrder = $order_arr[0]['dir'] ?? $defaultOrder;
            $requestedData = $columnName_arr[$columnIndex]['data'] ?? null;
            if ($requestedData && $requestedData !== 'no') {
                $columnName = match ($requestedData) {
                    'NOCUST', 'NMCUST', 'CODE02', 'DESC02', 'DESC03' => 'scctcust.' . $requestedData,
                    'NOVA' => 'scctcust.NOCUST',
                    default => 'sccttran.' . $requestedData,
                };
            }
        }

        $siswaFilter = '';
        $vaTypeFilter = 'all';
        $filter = $request->input('filter', []);
        foreach ($filter as $key => $val) {
            if ($val === null || $val === '' || strtolower((string) $val) === 'all') {
                continue;
            }

            if (in_array($key, ['siswa', 'nis'], true)) {
                $siswaFilter = trim((string) $val);
                continue;
            }

            if ($key === 'va_type') {
                $vaTypeFilter = strtolower(trim((string) $val));
                continue;
            }

            if (in_array($key, ['dari_tanggal', 'sampai_tanggal'], true) && preg_match('/^\d{2}-\d{2}-\d{4}$/', (string) $val)) {
                $date = Carbon::createFromFormat('d-m-Y', $val);
                if ($date) {
                    $filters[] = [
                        'sccttran.TRXDATE',
                        $key === 'dari_tanggal' ? '>=' : '<=',
                        $key === 'dari_tanggal' ? $date->copy()->startOfDay() : $date->copy()->endOfDay(),
                    ];
                }
            }
        }

        $schoolCodes = $this->resolveScopedSchoolCodes();
        if (!empty($schoolCodes)) {
            $filters[] = ['scctcust.CODE01', 'in', $schoolCodes];
        }

        $query = $this->excludeManualCashScope(
            sccttran::query()->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID'),
            'sccttran.FIDBANK'
        );

        if (in_array($vaTypeFilter, ['open', '1', 'cicil'], true)) {
            $query->whereRaw("TRIM(COALESCE(CAST(sccttran.REFFBANK AS CHAR), '')) LIKE ?", [scctcust::vaPrefixOpen() . '%']);
        } elseif (in_array($vaTypeFilter, ['close', '0'], true)) {
            $query->whereRaw("TRIM(COALESCE(CAST(sccttran.REFFBANK AS CHAR), '')) LIKE ?", [scctcust::vaPrefixClose() . '%']);
        }

        foreach ($filters as $filterRow) {
            if (count($filterRow) === 3 && ($filterRow[1] ?? null) === 'in' && is_array($filterRow[2] ?? null)) {
                $query->whereIn($filterRow[0], $filterRow[2]);
            } elseif (count($filterRow) === 3) {
                $query->where($filterRow[0], $filterRow[1], $filterRow[2]);
            }
        }

        if ($siswaFilter !== '') {
            $this->applySiswaLookup($query, $siswaFilter);
        }

        if (!blank($searchValue)) {
            $sanitizeSearch = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchValue);
            $query->where(function ($q) use ($searchValue, $sanitizeSearch) {
                $this->applySiswaLookup($q, $searchValue, false);
                $q->orWhere('sccttran.NOREFF', 'like', '%' . $sanitizeSearch . '%')
                    ->orWhere('sccttran.METODE', 'like', '%' . $sanitizeSearch . '%')
                    ->orWhereRaw("TRIM(COALESCE(CAST(sccttran.REFFBANK AS CHAR), '')) LIKE ?", ['%' . $sanitizeSearch . '%']);
            });
        }

        $totalRecords = $this->excludeManualCashScope(sccttran::query())->count();
        $totalRecordswithFilter = (clone $query)->count();

        $records = (clone $query)
            ->orderBy($columnName, $columnSortOrder)
            ->select([
                'scctcust.NOCUST',
                'scctcust.NUM2ND',
                'scctcust.NMCUST',
                'scctcust.CODE02',
                'scctcust.DESC02',
                'scctcust.DESC03',
                'sccttran.TRXDATE',
                'sccttran.METODE',
                'sccttran.DEBET',
                'sccttran.KREDIT',
                'sccttran.NOREFF',
                'sccttran.REFFBANK',
            ])
            ->skip($start)
            ->take($rowperpage > 0 ? $rowperpage : 25)
            ->get()
            ->map(function ($item) {
                $flag = scctcust::resolveInstallableFromReffBank($item->REFFBANK ?? null);
                $item->JENIS_VA = $flag === null ? '-' : scctcust::vaTypeLabel($flag);
                $reff = preg_replace('/\D/', '', (string) ($item->REFFBANK ?? ''));
                if (strlen($reff) >= 12) {
                    $item->NOVA = $reff;
                } elseif ($item->NOCUST && $item->NOCUST != '-') {
                    $item->NOVA = $flag === null ? scctcust::showVA($item->NOCUST) : scctcust::showVA($item->NOCUST, $flag);
                } else {
                    $item->NOVA = $flag === null ? scctcust::showVA($item->NUM2ND) : scctcust::showVA($item->NUM2ND, $flag);
                }

                if (!empty($item->TRXDATE)) {
                    try {
                        $item->TRXDATE = Carbon::parse($item->TRXDATE)->format('Y-m-d H:i:s');
                    } catch (\Throwable $e) {
                        // biarkan nilai asli
                    }
                }

                return $item;
            })
            ->toArray();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecordswithFilter,
            'data' => $records,
        ]);
    }
}
