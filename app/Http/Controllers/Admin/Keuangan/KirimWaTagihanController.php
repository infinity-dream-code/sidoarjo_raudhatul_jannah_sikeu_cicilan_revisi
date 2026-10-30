<?php

namespace App\Http\Controllers\Admin\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\LogWhatsapp;
use App\Models\mst_kelas;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\WaTemplate;
use App\Support\SchoolScope;
use App\Support\WhatsappGateway;
use App\Support\WhatsappTagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class KirimWaTagihanController extends Controller
{
    private string $title = 'Keuangan';
    private string $mainTitle = 'Kirim WA Tagihan';
    private string $dataTitle = 'Kirim WA Tagihan';
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
        return view('admin.keuangan.kirim_wa_tagihan.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'templates' => $this->activeTemplates(),
            'kelas' => mst_kelas::dropdownQuery($this->sekolah)
                ->orderBy('unit')
                ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
                ->orderByRaw("CASE WHEN kelas REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, kelas")
                ->get(),
            'thnAka' => mst_thn_aka::getMstThnAkaAttributes(),
            'dataUrl' => route('admin.keuangan.kirim-wa-tagihan.get-data'),
            'sendUrl' => route('admin.keuangan.kirim-wa-tagihan.send-wa'),
        ]);
    }

    public function getData(Request $request): JsonResponse
    {
        $draw = (int) $request->get('draw', 1);
        $start = (int) $request->get('start', 0);
        $length = (int) $request->get('length', 25);
        $search = trim((string) ($request->input('search.value') ?? ''));
        $filter = $request->input('filter', []);

        $query = $this->baseBillQuery();
        $this->applyFilters($query, $filter, $search);

        $recordsTotal = (clone $this->baseBillQuery())->count();
        $recordsFiltered = (clone $query)->count();

        $orderColumn = 'scctcust.nmcust';
        $orderDir = 'asc';
        $orderable = [
            'NOCUST' => 'scctcust.nocust',
            'NMCUST' => 'scctcust.nmcust',
            'NO_WA' => 'scctcust.NO_WA',
            'BTA' => 'scctbill.BTA',
            'BILLNM' => 'scctbill.BILLNM',
            'BILLAM' => 'scctbill.BILLAM',
            'PAYMENTLEFT' => 'scctbill.PAYMENTLEFT',
        ];
        $order = $request->input('order.0', []);
        $requested = $request->input('columns.' . ($order['column'] ?? '') . '.data');
        if (is_string($requested) && isset($orderable[$requested])) {
            $orderColumn = $orderable[$requested];
            $orderDir = strtolower((string) ($order['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        $rows = $query
            ->orderBy($orderColumn, $orderDir)
            ->orderBy('scctbill.AA', 'asc')
            ->skip($start)
            ->take($length === -1 ? max(1, $recordsFiltered) : max(1, $length))
            ->get();

        $data = $rows->map(function ($row) {
            $amounts = WhatsappTagihan::amounts(
                $row->BILLAM,
                $row->BILLPAID,
                $row->PAYMENTLEFT,
                $row->PAIDST
            );
            $hasWa = WhatsappTagihan::formatPhoneGateway($row->NO_WA) !== null;

            return [
                'AA' => $row->AA,
                'CUSTID' => $row->CUSTID,
                'NOCUST' => $row->NOCUST,
                'NMCUST' => $row->NMCUST,
                'NO_WA' => $row->NO_WA,
                'CODE02' => $row->CODE02,
                'DESC02' => $row->DESC02,
                'DESC03' => $row->DESC03,
                'BTA' => $row->BTA,
                'BILLNM' => $row->BILLNM,
                'BILLAC' => $row->BILLAC,
                'BILLAM' => $amounts['total'],
                'BILLPAID' => $amounts['terbayar'],
                'PAYMENTLEFT' => $amounts['sisa'],
                'has_wa' => $hasWa,
            ];
        })->values();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function sendWa(Request $request): JsonResponse
    {
        set_time_limit(500);

        $validator = Validator::make($request->all(), [
            'template_id' => ['required', 'integer'],
            'tagihan' => ['required', 'array', 'min:1'],
        ], [], [
            'template_id' => 'Template WA',
            'tagihan' => 'Tagihan',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'error' => $validator->errors(),
            ], 422);
        }

        $grouped = $this->normalizeTagihan($request->input('tagihan', []));
        if ($grouped === []) {
            return response()->json([
                'message' => 'Tidak ada data yang dipilih.',
            ], 422);
        }

        if (count($grouped) > 100) {
            return response()->json([
                'message' => 'Jumlah siswa yang dipilih tidak boleh lebih dari 100.',
            ], 413);
        }

        $templateId = (int) $request->input('template_id');
        $template = WhatsappTagihan::templateById($templateId);
        if ($template === null) {
            return response()->json([
                'message' => 'Template WA tidak ditemukan atau tidak aktif.',
            ], 422);
        }
        $template = WhatsappTagihan::withRincianPlaceholder($template);

        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($grouped as $custId => $billIds) {
            try {
                $result = $this->sendForStudent((string) $custId, $billIds, $template);
                if ($result === 'queued') {
                    $queued++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::channel('whatsapp')->error('kirim wa tagihan gagal', [
                    'custid' => $custId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($queued === 0 && $failed === 0) {
            return response()->json([
                'message' => 'Tidak ada pesan yang dikirim. Semua siswa terpilih belum punya nomor WhatsApp.',
                'queued' => $queued,
                'skipped' => $skipped,
                'failed' => $failed,
            ], 422);
        }

        if ($queued === 0) {
            return response()->json([
                'message' => 'Gagal mengantrekan pesan WhatsApp. Periksa koneksi gateway (DB_WA_* / WA_CLIENT_ID).',
                'queued' => $queued,
                'skipped' => $skipped,
                'failed' => $failed,
            ], 503);
        }

        $parts = [$queued . ' pesan masuk antrian'];
        if ($skipped > 0) {
            $parts[] = $skipped . ' dilewati karena No WA kosong';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' gagal';
        }

        return response()->json([
            'message' => implode('. ', $parts) . '.',
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normalizeTagihan(array $tagihan): array
    {
        $grouped = [];

        foreach ($tagihan as $custId => $billIds) {
            $custKey = trim((string) $custId);
            if ($custKey === '') {
                continue;
            }

            $ids = is_array($billIds) ? $billIds : [$billIds];
            $clean = [];
            foreach ($ids as $id) {
                $aa = trim((string) $id);
                if ($aa !== '') {
                    $clean[] = $aa;
                }
            }
            $clean = array_values(array_unique($clean));
            if ($clean === []) {
                continue;
            }

            $grouped[$custKey] = $clean;
        }

        return $grouped;
    }

    /**
     * @param  array<int, string>  $billIds
     */
    private function sendForStudent(string $custId, array $billIds, string $template): string
    {
        $siswaQuery = scctcust::query()->where('CUSTID', $custId);
        SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);
        $siswa = $siswaQuery->first();
        if (!$siswa) {
            return 'failed';
        }

        $phone = WhatsappTagihan::formatPhoneGateway($siswa->NO_WA ?? null);
        if ($phone === null) {
            return 'skipped';
        }

        $bills = $this->billsForStudent($custId, $billIds);
        if ($bills->isEmpty()) {
            return 'failed';
        }

        $total = 0;
        $terbayar = 0;
        $sisa = 0;
        foreach ($bills as $bill) {
            $amounts = WhatsappTagihan::amounts(
                $bill->BILLAM,
                $bill->BILLPAID,
                $bill->PAYMENTLEFT,
                $bill->PAIDST
            );
            $total += $amounts['total'];
            $terbayar += $amounts['terbayar'];
            $sisa += $amounts['sisa'];
        }

        $first = $bills->first();
        $nis = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-')
            ? $siswa->NOCUST
            : ($siswa->nocust ?? $siswa->NUM2ND ?? '');
        $kelasLabel = trim((string) ($siswa->DESC02 ?? '') . ' ' . (string) ($siswa->DESC03 ?? ''));
        $noVa = scctcust::showVA($nis, $first->isINSTALLABLE ?? null);

        $message = WhatsappTagihan::applyTemplate($template, [
            'nama' => $siswa->NMCUST ?? $siswa->nmcust ?? '-',
            'nis' => $nis !== '' ? $nis : '-',
            'no_daftar' => $siswa->NUM2ND ?? '-',
            'kelas' => $kelasLabel !== '' ? $kelasLabel : '-',
            'kelompok' => $siswa->DESC03 ?? '-',
            'unit' => $siswa->CODE02 ?? '-',
            'nama_tagihan' => $first->BILLNM ?? '-',
            'periode' => $first->BILLAC ?? $first->BTA ?? '-',
            'jumlah_tagihan' => WhatsappTagihan::formatRupiah($total),
            'terbayar' => WhatsappTagihan::formatRupiah($terbayar),
            'sisa_tagihan' => WhatsappTagihan::formatRupiah($sisa),
            'rincian' => WhatsappTagihan::rincian($bills),
            'no_va' => $noVa !== '' ? $noVa : '-',
        ]);

        $log = LogWhatsapp::create([
            'status' => 0,
            'log_id' => 0,
            'user_id' => Auth::id(),
            'custid' => $custId,
            'nama' => $siswa->NMCUST ?? $siswa->nmcust,
            'no_wa' => $phone,
            'pesan' => $message,
            'response' => '',
        ]);

        try {
            $result = WhatsappGateway::enqueue($phone, $message, $log->id);
            $log->response = json_encode($result, JSON_UNESCAPED_UNICODE);
            $log->save();
        } catch (Throwable $e) {
            $log->status = -1;
            $log->response = $e->getMessage();
            $log->save();
            throw $e;
        }

        return 'queued';
    }

    /**
     * @param  array<int, string>  $billIds
     */
    private function billsForStudent(string $custId, array $billIds)
    {
        $query = scctbill::query()
            ->join('scctcust', 'scctcust.CUSTID', '=', 'scctbill.CUSTID')
            ->where('scctbill.CUSTID', $custId)
            ->whereIn('scctbill.AA', $billIds)
            ->where('scctbill.FSTSBolehBayar', 1)
            ->whereRaw('CAST(COALESCE(scctcust.STCUST, 0) AS SIGNED) = 1')
            ->select([
                'scctbill.AA',
                'scctbill.CUSTID',
                'scctbill.BILLNM',
                'scctbill.BILLAC',
                'scctbill.BTA',
                'scctbill.BILLAM',
                'scctbill.BILLPAID',
                'scctbill.PAYMENTLEFT',
                'scctbill.PAIDST',
                'scctbill.isINSTALLABLE',
            ]);

        $this->applyBelumLunasScope($query);
        SchoolScope::apply($query, 'scctcust', $this->sekolah);

        return $query->get();
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
                'scctbill.BTA',
                'scctbill.BILLAM',
                'scctbill.BILLPAID',
                'scctbill.PAYMENTLEFT',
                'scctbill.PAIDST',
                'scctcust.nocust as NOCUST',
                'scctcust.nmcust as NMCUST',
                'scctcust.NO_WA',
                'scctcust.CODE02',
                'scctcust.DESC02',
                'scctcust.DESC03',
            ])
            ->where('scctbill.FSTSBolehBayar', 1)
            ->whereRaw('CAST(COALESCE(scctcust.STCUST, 0) AS SIGNED) = 1');

        $this->applyBelumLunasScope($query);
        SchoolScope::apply($query, 'scctcust', $this->sekolah);

        return $query;
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

    private function applyFilters($query, array $filter, string $search): void
    {
        $siswa = trim((string) ($filter['siswa'] ?? ''));
        if ($siswa !== '') {
            $sanitize = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $siswa);
            $query->where(function ($q) use ($siswa, $sanitize) {
                $q->where('scctcust.nmcust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.nocust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.NUM2ND', 'like', '%' . $sanitize . '%')
                    ->orWhereRaw('CAST(scctcust.CUSTID AS CHAR) LIKE ?', ['%' . $siswa . '%']);
            });
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

        $tahun = trim((string) ($filter['tahun_akademik'] ?? ''));
        if ($tahun !== '' && strtolower($tahun) !== 'all') {
            $query->where('scctbill.BTA', $tahun);
        }

        if ($search !== '') {
            $sanitize = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where(function ($q) use ($sanitize) {
                $q->where('scctcust.nmcust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.nocust', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctcust.NO_WA', 'like', '%' . $sanitize . '%')
                    ->orWhere('scctbill.BILLNM', 'like', '%' . $sanitize . '%');
            });
        }
    }

    private function activeTemplates()
    {
        try {
            return WaTemplate::query()
                ->where('is_active', 1)
                ->orderBy('nama')
                ->get(['id', 'kode', 'nama']);
        } catch (Throwable) {
            return collect();
        }
    }
}
