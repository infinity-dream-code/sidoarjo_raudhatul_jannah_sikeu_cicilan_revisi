<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Support\PerpanjangTagihanExpiredProcedure;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PerpanjangExpiredController extends Controller
{
    private string $title = 'Keuangan';
    private string $mainTitle = 'Tagihan Siswa';
    private string $dataTitle = 'Perpanjang Tagihan Expired';
    private string $cacheKey = 'data_tagihan';
    public ?string $sekolah = null;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $user = Auth::user();
                $this->sekolah = $user->sekolah ?? $user->unit ?? null;
            }
            return $next($request);
        });
    }

    public function index()
    {
        $autoExp = DataTagihanController::resolveAutoExtendExpDate();

        return view('admin.keuangan.tagihan_siswa.perpanjang_expired.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'kelas' => mst_kelas::dropdownQuery($this->sekolah)
                ->orderBy('unit')
                ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
                ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
                ->get(),
            'periode' => scctbill::query()
                ->whereNotNull('BILLAC')
                ->where('BILLAC', '!=', '')
                ->whereNotNull('ExpDate')
                ->where('FSTSBolehBayar', 1)
                ->distinct()
                ->orderBy('BILLAC', 'desc')
                ->pluck('BILLAC'),
            'autoExpDate' => $autoExp->format('Y-m-d'),
            'autoExpDateLabel' => $autoExp->translatedFormat('d F Y'),
            'dataUrl' => route('admin.keuangan.tagihan-siswa.perpanjang-expired.get-data'),
            'storeUrl' => route('admin.keuangan.tagihan-siswa.perpanjang-expired.store'),
            'autoAllUrl' => route('admin.keuangan.tagihan-siswa.perpanjang-expired.auto-all'),
            'backUrl' => route('admin.keuangan.tagihan-siswa.data-tagihan.index'),
        ]);
    }

    public function getData(Request $request)
    {
        $draw = (int) $request->get('draw', 1);
        $start = (int) $request->get('start', 0);
        $length = (int) $request->get('length', 25);
        $search = trim((string) ($request->input('search.value') ?? ''));
        $filter = $request->input('filter', []);
        $expiredFilter = $this->normalizeExpiredFilter($filter['expired'] ?? 'ya');

        $query = $this->baseBillQuery();
        $this->applyExpiredFilter($query, $expiredFilter);

        if (!blank($search)) {
            $sanitize = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where(function ($q) use ($sanitize) {
                $q->where('scctcust.nmcust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.nocust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.NUM2ND', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctbill.BILLNM', 'like', '%' . $sanitize . '%');
            });
        }

        $periode = trim((string) ($filter['periode'] ?? ''));
        if ($periode !== '' && strtolower($periode) !== 'all') {
            $query->where('scctbill.BILLAC', $periode);
        }

        $kelas = trim((string) ($filter['kelas'] ?? ''));
        if ($kelas !== '' && strtolower($kelas) !== 'all' && str_contains($kelas, '~~')) {
            $parts = explode('~~', $kelas);
            if (count($parts) === 3) {
                if (!$this->sekolah) {
                    $query->where('scctcust.CODE02', $parts[0]);
                }
                $query->where('scctcust.DESC02', $parts[1])
                    ->where('scctcust.DESC03', $parts[2]);
            }
        }

        $siswa = trim((string) ($filter['siswa'] ?? ''));
        if ($siswa !== '') {
            if (is_numeric($siswa)) {
                $query->where(function ($q) use ($siswa) {
                    $q->where('scctcust.nocust', 'like', '%' . $siswa . '%')
                        ->orWhere('scctcust.NUM2ND', 'like', '%' . $siswa . '%')
                        ->orWhereRaw('CAST(scctcust.CUSTID AS CHAR) LIKE ?', ['%' . $siswa . '%']);
                });
            } else {
                $sanitize = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $siswa);
                $query->where(function ($q) use ($sanitize) {
                    $q->where('scctcust.nmcust', 'like', '%' . $sanitize . '%')
                        ->orWhere('scctcust.nocust', 'like', '%' . $sanitize . '%');
                });
            }
        }

        $totalQuery = $this->baseBillQuery();
        $this->applyExpiredFilter($totalQuery, $expiredFilter);
        $recordsTotal = (clone $totalQuery)->count();
        $recordsFiltered = (clone $query)->count();

        $rows = $query
            ->orderBy('scctbill.ExpDate', 'asc')
            ->orderBy('scctcust.nmcust', 'asc')
            ->skip($start)
            ->take($length === -1 ? $recordsFiltered : max(1, $length))
            ->get();

        $today = Carbon::now()->startOfDay();
        $data = $rows->map(function ($row) use ($today) {
            $exp = $row->ExpDate ? Carbon::parse($row->ExpDate) : null;
            $isExpired = $exp ? $exp->lt($today) : false;
            $daysOver = $exp ? $exp->copy()->startOfDay()->diffInDays($today, false) : null;

            return [
                'AA' => $row->AA,
                'item_id' => $row->AA,
                'CUSTID' => $row->CUSTID,
                'NOCUST' => $row->NOCUST,
                'NMCUST' => $row->NMCUST,
                'CODE02' => $row->CODE02,
                'DESC02' => $row->DESC02,
                'DESC03' => $row->DESC03,
                'BILLNM' => $row->BILLNM,
                'BILLAC' => $row->BILLAC,
                'BILLAM' => (int) ($row->BILLAM ?? 0),
                'PAYMENTLEFT' => (int) ($row->PAYMENTLEFT ?? max(0, (int) $row->BILLAM - (int) $row->BILLPAID)),
                'BILLPAID' => (int) ($row->BILLPAID ?? 0),
                'ExpDate' => $exp ? $exp->format('d-m-Y') : null,
                'ExpDate_raw' => $exp ? $exp->format('Y-m-d H:i:s') : null,
                'is_expired' => $isExpired,
                'expired_label' => $isExpired ? 'Ya' : 'Tidak',
                'days_overdue' => $daysOver !== null && $daysOver > 0 ? (int) $daysOver : 0,
            ];
        })->values();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
            'mode' => ['required', 'in:auto,custom'],
            'exp_date' => ['nullable', 'date', 'required_if:mode,custom'],
        ], [], [
            'ids' => 'Tagihan',
            'mode' => 'Mode perpanjang',
            'exp_date' => 'Tanggal expired',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'error' => $validator->errors(),
            ], 422);
        }

        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return response()->json(['message' => 'Pilih minimal 1 tagihan.'], 422);
        }

        if ($request->input('mode') === 'auto') {
            $newExp = DataTagihanController::resolveAutoExtendExpDate();
        } else {
            try {
                $newExp = Carbon::parse($request->input('exp_date'))->endOfDay();
            } catch (\Throwable) {
                return response()->json(['message' => 'Tanggal expired tidak valid.'], 422);
            }
        }

        $query = scctbill::query()
            ->whereIn('AA', $ids)
            ->where('FSTSBolehBayar', 1)
            ->whereNotNull('ExpDate')
            ->where('ExpDate', '<', Carbon::now()->startOfDay());

        $this->applyBelumLunasScope($query);

        $tagihans = $query->get();
        if ($tagihans->isEmpty()) {
            return response()->json(['message' => 'Tagihan expired tidak ditemukan / sudah diperpanjang.'], 422);
        }

        try {
            DB::connection('DATA_MYSQL')->beginTransaction();

            $updated = 0;
            foreach ($tagihans as $tagihan) {
                $tagihan->ExpDate = $newExp->format('Y-m-d H:i:s');
                $tagihan->save();
                $updated++;
            }

            Cache::increment(Str::slug($this->cacheKey) . '_cache_version');
            DB::connection('DATA_MYSQL')->commit();

            return response()->json([
                'message' => "Berhasil memperpanjang {$updated} tagihan sampai {$newExp->translatedFormat('d F Y')}.",
                'exp_date' => $newExp->format('Y-m-d H:i:s'),
                'updated' => $updated,
            ]);
        } catch (\Throwable $e) {
            DB::connection('DATA_MYSQL')->rollBack();

            return response()->json([
                'message' => 'Gagal memperpanjang expired date: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function autoAll()
    {
        try {
            $result = PerpanjangTagihanExpiredProcedure::call($this->sekolah);
            $updated = (int) ($result['updated'] ?? 0);
            $expRaw = $result['exp_date'] ?? null;
            $newExp = $expRaw
                ? Carbon::parse($expRaw)
                : DataTagihanController::resolveAutoExtendExpDate();

            Cache::increment(Str::slug($this->cacheKey) . '_cache_version');

            if ($updated <= 0) {
                return response()->json([
                    'message' => 'Tidak ada tagihan expired yang perlu diperpanjang.',
                    'updated' => 0,
                    'exp_date' => $newExp->format('Y-m-d H:i:s'),
                ], 200);
            }

            return response()->json([
                'message' => "Berhasil memperpanjang otomatis {$updated} tagihan sampai {$newExp->translatedFormat('d F Y')}.",
                'updated' => $updated,
                'exp_date' => $newExp->format('Y-m-d H:i:s'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('perpanjang-expired.auto-all.failed', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Gagal perpanjang otomatis: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function baseBillQuery()
    {
        $query = scctbill::query()
            ->join('scctcust', 'scctcust.CUSTID', '=', 'scctbill.CUSTID')
            ->select([
                'scctbill.AA',
                'scctbill.CUSTID',
                'scctbill.BILLNM',
                'scctbill.BILLAC',
                'scctbill.BILLAM',
                'scctbill.BILLPAID',
                'scctbill.PAYMENTLEFT',
                'scctbill.ExpDate',
                'scctcust.nocust as NOCUST',
                'scctcust.nmcust as NMCUST',
                'scctcust.CODE02',
                'scctcust.DESC02',
                'scctcust.DESC03',
            ])
            ->where('scctbill.FSTSBolehBayar', 1)
            ->whereNotNull('scctbill.ExpDate')
            ->whereRaw('CAST(COALESCE(scctcust.STCUST, 0) AS SIGNED) = 1');

        $this->applyBelumLunasScope($query);
        SchoolScope::apply($query, 'scctcust', $this->sekolah);

        return $query;
    }

    /**
     * Expired = ExpDate < awal hari ini (contoh: ExpDate 20 Sep, hari ini 10 Okt → expired).
     */
    private function applyExpiredFilter($query, string $expiredFilter): void
    {
        $todayStart = Carbon::now()->startOfDay();

        if ($expiredFilter === 'ya') {
            $query->where('scctbill.ExpDate', '<', $todayStart);
            return;
        }

        if ($expiredFilter === 'tidak') {
            $query->where('scctbill.ExpDate', '>=', $todayStart);
            return;
        }

        // all: tidak filter expired status
    }

    private function normalizeExpiredFilter(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? 'ya')));

        return match ($normalized) {
            'tidak', '0', 'no', 'belum' => 'tidak',
            'all', 'semua' => 'all',
            default => 'ya',
        };
    }

    private function applyBelumLunasScope($query, string $billTable = 'scctbill'): void
    {
        $sisaExpr = "CAST(COALESCE({$billTable}.PAYMENTLEFT, {$billTable}.BILLAM - COALESCE({$billTable}.BILLPAID, 0), 0) AS SIGNED)";

        $query->where(function ($q) use ($billTable, $sisaExpr) {
            $q->where("{$billTable}.PAIDST", 0)
                ->orWhereNull("{$billTable}.PAIDST")
                ->orWhereRaw("{$sisaExpr} > 0");
        });
    }
}
