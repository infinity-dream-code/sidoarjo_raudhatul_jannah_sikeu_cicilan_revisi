<?php

namespace App\Http\Controllers\Admin\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\mst_kelas;
use App\Models\mst_thn_aka;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\WaTemplate;
use App\Support\EmailBroadcaster;
use App\Support\SchoolScope;
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
    private string $mainTitle = 'Kirim Tagihan Email';
    private string $dataTitle = 'Kirim Tagihan Email';
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
            'EMAIL_ORTU' => 'scctcust.EMAIL_ORTU',
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
            $email = trim((string) ($row->EMAIL_ORTU ?? ''));
            $hasEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

            return [
                'AA' => $row->AA,
                'CUSTID' => $row->CUSTID,
                'NOCUST' => $row->NOCUST,
                'NMCUST' => $row->NMCUST,
                'EMAIL_ORTU' => $email !== '' ? $email : null,
                'CODE02' => $row->CODE02,
                'DESC02' => $row->DESC02,
                'DESC03' => $row->DESC03,
                'BTA' => $row->BTA,
                'BILLNM' => $row->BILLNM,
                'BILLAC' => $row->BILLAC,
                'BILLAM' => $amounts['total'],
                'BILLPAID' => $amounts['terbayar'],
                'PAYMENTLEFT' => $amounts['sisa'],
                'has_email' => (bool) $hasEmail,
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
            'template_id' => 'Template Email',
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
        $templateMeta = $this->activeTemplateMeta($templateId);
        if ($templateMeta === null) {
            return response()->json([
                'message' => 'Template Email tidak ditemukan atau tidak aktif.',
            ], 422);
        }

        $messages = [];
        $skipped = 0;
        $failed = 0;

        foreach ($grouped as $custId => $billIds) {
            try {
                $payload = $this->buildBillingPayload((string) $custId, $billIds, $templateMeta['nama']);
                if ($payload === null) {
                    $skipped++;
                    continue;
                }
                if ($payload === false) {
                    $failed++;
                    continue;
                }
                $messages[] = $payload;
            } catch (Throwable $e) {
                $failed++;
                Log::error('kirim tagihan email build gagal', [
                    'custid' => $custId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($messages === [] && $failed === 0) {
            return response()->json([
                'message' => 'Tidak ada pesan yang dikirim. Semua siswa terpilih belum punya email ortu.',
                'queued' => 0,
                'skipped' => $skipped,
                'failed' => $failed,
            ], 422);
        }

        $queued = 0;
        if ($messages !== []) {
            try {
                $result = EmailBroadcaster::enqueueBilling($messages);
                $queued = (int) ($result['queued'] ?? 0);
                $failed += (int) ($result['failed'] ?? 0);
            } catch (Throwable $e) {
                Log::error('kirim tagihan email enqueue gagal', [
                    'count' => count($messages),
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Gagal mengantrekan pesan email. Periksa EMAIL_BROADCASTER_URL / token / koneksi ke Email Broadcaster.',
                    'queued' => 0,
                    'skipped' => $skipped,
                    'failed' => $failed + count($messages),
                    'error' => $e->getMessage(),
                ], 503);
            }
        }

        if ($queued === 0) {
            return response()->json([
                'message' => 'Gagal mengantrekan pesan email. Periksa EMAIL_BROADCASTER_URL / token / koneksi ke Email Broadcaster.',
                'queued' => $queued,
                'skipped' => $skipped,
                'failed' => $failed,
            ], 503);
        }

        // 1–3 pesan: langsung success ke UI, process-now jalan setelah response terkirim.
        $immediateMax = max(0, (int) config('email_broadcaster.immediate_max', 3));
        if ($queued > 0 && $queued <= $immediateMax) {
            dispatch(function () use ($queued) {
                try {
                    EmailBroadcaster::processNow();
                } catch (Throwable $e) {
                    Log::warning('kirim tagihan email process-now background gagal', [
                        'queued' => $queued,
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        }

        return response()->json([
            'message' => $queued . ' pesan masuk antrian email',
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
     * @return array<string, mixed>|null|false null=skipped (no email), false=failed, array=payload
     */
    private function buildBillingPayload(string $custId, array $billIds, string $templateName): array|false|null
    {
        $siswaQuery = scctcust::query()->where('CUSTID', $custId);
        SchoolScope::apply($siswaQuery, 'scctcust', $this->sekolah);
        $siswa = $siswaQuery->first();
        if (!$siswa) {
            return false;
        }

        $email = trim((string) ($siswa->EMAIL_ORTU ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $bills = $this->billsForStudent($custId, $billIds);
        if ($bills->isEmpty()) {
            return false;
        }

        $total = 0;
        $sisa = 0;
        $items = [];
        foreach ($bills as $bill) {
            $amounts = WhatsappTagihan::amounts(
                $bill->BILLAM,
                $bill->BILLPAID,
                $bill->PAYMENTLEFT,
                $bill->PAIDST
            );
            $total += $amounts['total'];
            $sisa += $amounts['sisa'];

            $itemName = trim((string) ($bill->BILLNM ?? 'Tagihan'));
            $periode = trim((string) ($bill->BILLAC ?? $bill->BTA ?? ''));
            if ($periode !== '') {
                $itemName .= ' (' . $periode . ')';
            }

            $items[] = [
                'name' => $itemName,
                'amount' => $amounts['sisa'] > 0 ? $amounts['sisa'] : $amounts['total'],
            ];
        }

        $first = $bills->first();
        $nis = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-')
            ? $siswa->NOCUST
            : ($siswa->nocust ?? $siswa->NUM2ND ?? '');
        $nama = (string) ($siswa->NMCUST ?? $siswa->nmcust ?? '-');
        $kelasLabel = trim((string) ($siswa->DESC02 ?? '') . ' ' . (string) ($siswa->DESC03 ?? ''));
        $noVa = scctcust::showVA($nis, $first->isINSTALLABLE ?? null);

        $subject = 'Informasi tagihan ananda ' . $nama;
        if ($kelasLabel !== '') {
            $subject .= ' (' . $kelasLabel . ')';
        }

        return [
            'nama' => $nama,
            'email' => $email,
            'nis' => $nis !== '' ? $nis : null,
            'kelas' => $kelasLabel !== '' ? $kelasLabel : null,
            'va' => $noVa !== '' ? $noVa : null,
            'subject' => $subject,
            'organizationName' => (string) (config('app.nama_instansi') ?: 'Raudlatul Jannah Sidoarjo'),
            'paymentLink' => 'https://raudlatuljannah-sidoarjo.smartpayment.co.id',
            'totalAmount' => $total,
            'outstandingAmount' => $sisa,
            'items' => $items,
        ];
    }

    /**
     * @return array{id:int,nama:string}|null
     */
    private function activeTemplateMeta(int $templateId): ?array
    {
        try {
            $row = WaTemplate::query()
                ->where('id', $templateId)
                ->where('is_active', 1)
                ->first(['id', 'nama']);
            if (!$row) {
                return null;
            }

            return [
                'id' => (int) $row->id,
                'nama' => trim((string) ($row->nama ?? '')),
            ];
        } catch (Throwable) {
            return null;
        }
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
                'scctcust.EMAIL_ORTU',
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
                    ->orWhere('scctcust.EMAIL_ORTU', 'like', '%' . $sanitize . '%')
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
