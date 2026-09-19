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

    /** open | close — ditentukan dari route prefix. */
    private function resolveVaMode(): string
    {
        $route = (string) (request()->route()?->getName() ?? '');
        $path = (string) request()->path();

        if (str_contains($route, 'saldo-va-open') || str_contains($path, 'saldo-va-open')) {
            return 'open';
        }

        return 'close';
    }

    private function isVaOpen(): bool
    {
        return $this->resolveVaMode() === 'open';
    }

    private function vaInstallableFlag(): int
    {
        return $this->isVaOpen() ? 1 : 0;
    }

    private function currentVaPrefix(): string
    {
        $raw = preg_replace(
            '/\D/',
            '',
            $this->isVaOpen() ? scctcust::vaPrefixOpen() : scctcust::vaPrefixClose()
        );

        return $raw !== '' ? $raw : ($this->isVaOpen() ? '797790' : '797789');
    }

    private function routePrefixName(): string
    {
        return $this->isVaOpen() ? 'saldo-va-open' : 'saldo-va-close';
    }

    private function saldoRoute(string $name, array $params = []): string
    {
        return route('admin.keuangan.saldo.' . $this->routePrefixName() . '.' . $name, $params);
    }

    private function applyPageTitles(): void
    {
        $label = $this->isVaOpen() ? 'Saldo VA Open' : 'Saldo VA Close';
        $this->dataTitle = $label;
        $this->showTitle = 'Detail ' . $label;
    }

    private function applyReffBankPrefixFilter($query, string $column = 'REFFBANK')
    {
        $prefix = $this->currentVaPrefix();

        return $query->whereRaw(
            "TRIM(COALESCE(CAST({$column} AS CHAR), '')) LIKE ?",
            [$prefix . '%']
        );
    }

    /**
     * Lepas file-lock session agar request AJAX paralel tidak bentrok
     * (penyebab error "gangguan sementara" yang intermittent).
     */
    private function releaseSessionLock(): void
    {
        try {
            if (request()->hasSession()) {
                request()->session()->save();
            }
        } catch (\Throwable) {
        }
    }

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
        $this->dataTitle = 'Saldo VA';
        $this->showTitle = 'Detail Saldo VA';
    }

    public function index()
    {
        $this->applyPageTitles();
        $this->releaseSessionLock();
        $schoolCodes = $this->resolveScopedSchoolCodes();
        $cacheSuffix = md5(json_encode([$this->sekolah, $schoolCodes]));

        $data['thn_aka'] = Cache::remember('saldo_va_thn_aka', 600, function () {
            return mst_thn_aka::getMstThnAkaAttributes();
        });
        $data['sekolah'] = Cache::remember('saldo_va_sekolah_' . $cacheSuffix, 600, function () use ($schoolCodes) {
            return mst_sekolah::select(['CODE01', 'DESC01'])
                ->when(!empty($schoolCodes), function ($query) use ($schoolCodes) {
                    $query->whereIn('CODE01', $schoolCodes);
                })
                ->orderBy('DESC01')
                ->get();
        });
        $data['kelas'] = Cache::remember('saldo_va_kelas_' . $cacheSuffix, 600, function () {
            return mst_kelas::dropdownQuery($this->sekolah)
                ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
                ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
                ->get();
        });
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['vaMode'] = $this->resolveVaMode();
        $data['vaPrefix'] = $this->currentVaPrefix();
        $data['columnsUrl'] = $this->saldoRoute('get-column');
        $data['datasUrl'] = $this->saldoRoute('get-data');
        $data['dataTransaksiUrl'] = $this->saldoRoute('data-transaksi.index');
        $data['indexUrl'] = $this->saldoRoute('index');

        return view('admin.keuangan.saldo.saldo_virtual_account.index', $data);
    }

    public function show($id)
    {
        $this->applyPageTitles();
        try {
            $flag = $this->vaInstallableFlag();
            $data['title'] = $this->title;
            $data['mainTitle'] = $this->mainTitle;
            $data['dataTitle'] = $this->dataTitle;
            $data['showTitle'] = $this->showTitle;
            $data['indexUrl'] = $this->saldoRoute('index');
            $data['columnsUrl'] = $this->saldoRoute('transaksi.get-column');
            $data['datasUrl'] = $this->saldoRoute('transaksi.get-data', ['CUSTID' => $id]);
            $data['exportTransaksiUrl'] = $this->saldoRoute('export', ['id' => $id]);
            $data['vaMode'] = $this->resolveVaMode();

            $data['siswa'] = scctcust::find($id);

            if ($data['siswa']) {
                $nis = ($data['siswa']->NOCUST && $data['siswa']->NOCUST != '-')
                    ? $data['siswa']->NOCUST
                    : $data['siswa']->NUM2ND;
                $data['siswa']->NOVA = scctcust::showVA($nis, $flag);

                $trxBase = $this->applyReffBankPrefixFilter(
                    $this->excludeManualCashScope(sccttran::query())->where('CUSTID', $id)
                );
                $data['totalKredit'] = (int) (clone $trxBase)->sum('KREDIT');
                $data['totalDebet'] = (int) (clone $trxBase)->sum('DEBET');
            } else {
                throw new Exception('Siswa tidak ditemukan');
            }

            return view('admin.keuangan.saldo.saldo_virtual_account.show', $data);
        } catch (\Exception $e) {
            return redirect()->to($this->saldoRoute('index'))->with('error', 'Siswa tidak ditemukan!');
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
        $this->releaseSessionLock();

        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
            ['data' => 'NOVA', 'name' => 'NO VA', 'searchable' => false, 'orderable' => false, 'exportable' => true],
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
                'buttonLink' => route('admin.keuangan.saldo.' . $this->routePrefixName() . '.show', ':id'),
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
        $this->releaseSessionLock();

        try {
            return $this->buildSaldoDataResponse($request);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'draw' => (int) $request->get('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Gagal memuat data saldo. Silakan coba lagi.',
            ], 500);
        }
    }

    private function buildSaldoDataResponse(Request $request)
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

        $orderableMap = [
            'NOCUST' => 'scctcust.NOCUST',
            'NMCUST' => 'scctcust.NMCUST',
            'CODE02' => 'scctcust.CODE02',
            'DESC02' => 'scctcust.DESC02',
            'DESC03' => 'scctcust.DESC03',
            'NUM2ND' => 'scctcust.NUM2ND',
            'DESC04' => 'scctcust.DESC04',
            'saldo' => 'saldo_total',
        ];
        if (isset($orderableMap[$columnName])) {
            $columnName = $orderableMap[$columnName];
        } elseif (!str_contains((string) $columnName, '.')) {
            $columnName = $defaultColumn;
        }

        $siswaFilter = '';
        $needsSaldoFilter = false;
        $filter = $request->input('filter');
        if ($filter) {
            foreach ($filter as $key => $val) {
                if (strtolower((string) $val) != 'all' && $val !== null && $val !== '') {
                    if ($key == 'siswa') {
                        $siswaFilter = trim((string) $val);
                    } else if ($key == 'kelas') {
                        $filters[] = ['scctcust.CODE03', '=', $val];
                    } else if ($key === 'sekolah') {
                        $filters[] = ['scctcust.CODE01', '=', trim((string) $val)];
                    } else if ($key === 'angkatan') {
                        $filters[] = ['scctcust.DESC04', '=', $val];
                    } else if ($key == 'saldo_positif') {
                        if ((string) $val === '1') {
                            $needsSaldoFilter = true;
                        }
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

        $vaFlag = $this->vaInstallableFlag();
        $orderBySaldo = $columnName === 'saldo_total';
        $needsFullSaldoJoin = $orderBySaldo || $needsSaldoFilter;

        $saldoSelect = [
            'CUSTID',
            DB::raw("COALESCE(SUM(KREDIT), 0) AS kredit"),
            DB::raw("COALESCE(SUM(DEBET), 0) AS debet"),
        ];

        $applyCustFilters = function ($query) use ($filterQuery, $siswaFilter, $searchValue) {
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
            return $query;
        };

        $scopedCodesForCount = $this->resolveScopedSchoolCodes();
        $totalRecords = Cache::remember('scctcust_total_count_' . md5(json_encode($scopedCodesForCount)), 600, function () use ($scopedCodesForCount) {
            return scctcust::when(!empty($scopedCodesForCount), function ($query) use ($scopedCodesForCount) {
                $query->whereIn('CODE01', $scopedCodesForCount);
            })->count('CUSTID');
        });

        if ($needsFullSaldoJoin) {
            $saldoAgg = $this->applyReffBankPrefixFilter(
                $this->excludeManualCashScope(sccttran::query())
            )
                ->select($saldoSelect)
                ->groupBy('CUSTID');

            $query = scctcust::query()
                ->leftJoinSub($saldoAgg, 'trx', function ($join) {
                    $join->on('trx.CUSTID', '=', 'scctcust.CUSTID');
                });
            $applyCustFilters($query);

            if ($needsSaldoFilter) {
                $query->whereRaw('(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0)) > 0');
            }

            $totalRecordswithFilter = (clone $query)->count('scctcust.CUSTID');

            $orderSql = $orderBySaldo
                ? DB::raw('(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0))')
                : $columnName;

            $pageStudents = $query
                ->select($select)
                ->addSelect([
                    DB::raw('COALESCE(trx.kredit, 0) AS kredit'),
                    DB::raw('COALESCE(trx.debet, 0) AS debet'),
                    DB::raw('(COALESCE(trx.kredit, 0) - COALESCE(trx.debet, 0)) AS saldo'),
                ])
                ->orderBy($orderSql, $columnSortOrder)
                ->skip((int) $start)
                ->take(max(1, (int) $rowperpage))
                ->get();
        } else {
            $countQuery = scctcust::query();
            $applyCustFilters($countQuery);
            $totalRecordswithFilter = $countQuery->count('scctcust.CUSTID');

            $pageStudents = $applyCustFilters(scctcust::query())
                ->select($select)
                ->orderBy($columnName, $columnSortOrder)
                ->skip((int) $start)
                ->take(max(1, (int) $rowperpage))
                ->get();

            $custIds = $pageStudents->pluck('CUSTID')->filter()->values()->all();
            $saldoByCust = collect();
            if (!empty($custIds)) {
                $saldoByCust = $this->applyReffBankPrefixFilter(
                    $this->excludeManualCashScope(sccttran::query())->whereIn('CUSTID', $custIds)
                )
                    ->select($saldoSelect)
                    ->groupBy('CUSTID')
                    ->get()
                    ->keyBy('CUSTID');
            }

            foreach ($pageStudents as $item) {
                $s = $saldoByCust->get($item->CUSTID);
                $kredit = (int) ($s->kredit ?? 0);
                $debet = (int) ($s->debet ?? 0);
                $item->setAttribute('kredit', $kredit);
                $item->setAttribute('debet', $debet);
                $item->setAttribute('saldo', $kredit - $debet);
            }
        }

        $records = [];
        foreach ($pageStudents as $item) {
            $attrs = $item->getAttributes();
            $nis = (!empty($attrs['NOCUST']) && $attrs['NOCUST'] !== '-')
                ? $attrs['NOCUST']
                : ($attrs['NUM2ND'] ?? '');
            $custId = $attrs['CUSTID'] ?? $item->CUSTID ?? null;

            $records[] = [
                'item_id' => $custId,
                'print' => true,
                'NOCUST' => $attrs['NOCUST'] ?? null,
                'NUM2ND' => $attrs['NUM2ND'] ?? null,
                'NMCUST' => $attrs['NMCUST'] ?? null,
                'CODE02' => $attrs['CODE02'] ?? null,
                'DESC02' => $attrs['DESC02'] ?? null,
                'DESC03' => $attrs['DESC03'] ?? null,
                'DESC04' => $attrs['DESC04'] ?? null,
                'va_type' => $this->resolveVaMode(),
                'NOVA' => scctcust::showVA($nis, $vaFlag),
                'saldo' => (int) ($attrs['saldo'] ?? 0),
                'kredit' => (int) ($attrs['kredit'] ?? 0),
                'debet' => (int) ($attrs['debet'] ?? 0),
            ];
        }

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecordswithFilter,
            'data' => $records,
        ]);
    }

    public function getColumnTran()
    {
        $this->releaseSessionLock();

        return [
            ['data' => null, 'columnType' => 'row', 'name' => 'No', 'exportable' => true],
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
        $this->releaseSessionLock();

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

        $query = $this->applyReffBankPrefixFilter(
            $this->excludeManualCashScope(
                sccttran::query()->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID'),
                'sccttran.FIDBANK'
            ),
            'sccttran.REFFBANK'
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

        $totalRecords = $this->applyReffBankPrefixFilter(
            $this->excludeManualCashScope(sccttran::query())
                ->when($custid, fn ($q) => $q->where('CUSTID', $custid))
        )->count();

        $totalRecordswithFilter = (clone $query)->count();

        $vaFlag = $this->vaInstallableFlag();
        $records = (clone $query)
            ->orderBy($columnName, $columnSortOrder)
            ->select($select)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) use ($vaFlag) {
                unset($item->id);
                $reff = preg_replace('/\D/', '', (string) ($item->REFFBANK ?? ''));
                if (strlen($reff) >= 12) {
                    $item->NOVA = $reff;
                } else {
                    $nis = ($item->NOCUST && $item->NOCUST != '-') ? $item->NOCUST : ($item->NUM2ND ?? '');
                    $item->NOVA = scctcust::showVA($nis, $vaFlag);
                }

                return $item;
            })
            ->toArray();

        $totalKredit = 0;
        $totalDebet = 0;

        if ($custid) {
            $modeKey = $this->resolveVaMode();
            $totalKredit = Cache::remember(
                "total_kredit_va_{$modeKey}_custid_" . $custid,
                600,
                fn () => (int) $this->applyReffBankPrefixFilter(
                    $this->excludeManualCashScope(sccttran::query())->where('CUSTID', $custid)
                )->sum('KREDIT')
            );

            $totalDebet = Cache::remember(
                "total_debet_va_{$modeKey}_custid_" . $custid,
                600,
                fn () => (int) $this->applyReffBankPrefixFilter(
                    $this->excludeManualCashScope(sccttran::query())->where('CUSTID', $custid)
                )->sum('DEBET')
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
                'debet' => ['location' => 4, 'value' => $totalDebet, 'columnType' => 'currency'],
                'kredit' => ['location' => 5, 'value' => $totalKredit, 'columnType' => 'currency'],
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
        $this->releaseSessionLock();

        return response()->json([
            'saldo' => $this->resolveCustSaldo($request->input('siswa')),
        ]);
    }

    public function transaksiIndex()
    {
        $this->applyPageTitles();
        $this->releaseSessionLock();
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->dataTitle;
        $data['pageTitle'] = 'Data Transaksi';
        $data['indexUrl'] = $this->saldoRoute('index');
        $data['columnsUrl'] = $this->saldoRoute('data-transaksi.get-column');
        $data['datasUrl'] = $this->saldoRoute('data-transaksi.get-data');
        $data['prefillSiswa'] = trim((string) request()->query('siswa', request()->query('nis', '')));
        $data['vaMode'] = $this->resolveVaMode();

        return view('admin.keuangan.saldo.saldo_virtual_account.data_transaksi', $data);
    }

    public function getColumnDataTransaksi()
    {
        $this->releaseSessionLock();

        return [
            ['data' => null, 'name' => 'no', 'columnType' => 'row', 'exportable' => true],
            ['data' => 'NOCUST', 'name' => 'NIS', 'searchable' => true, 'orderable' => true, 'exportable' => true],
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
        $this->releaseSessionLock();

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
        $filter = $request->input('filter', []);
        foreach ($filter as $key => $val) {
            if ($val === null || $val === '' || strtolower((string) $val) === 'all') {
                continue;
            }

            if (in_array($key, ['siswa', 'nis'], true)) {
                $siswaFilter = trim((string) $val);
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

        $query = $this->applyReffBankPrefixFilter(
            $this->excludeManualCashScope(
                sccttran::query()->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID'),
                'sccttran.FIDBANK'
            ),
            'sccttran.REFFBANK'
        );

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

        $totalRecords = $this->applyReffBankPrefixFilter(
            $this->excludeManualCashScope(sccttran::query())
        )->count();
        $totalRecordswithFilter = (clone $query)->count();

        $vaFlag = $this->vaInstallableFlag();
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
            ->map(function ($item) use ($vaFlag) {
                $reff = preg_replace('/\D/', '', (string) ($item->REFFBANK ?? ''));
                if (strlen($reff) >= 12) {
                    $item->NOVA = $reff;
                } elseif ($item->NOCUST && $item->NOCUST != '-') {
                    $item->NOVA = scctcust::showVA($item->NOCUST, $vaFlag);
                } else {
                    $item->NOVA = scctcust::showVA($item->NUM2ND ?? '', $vaFlag);
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
