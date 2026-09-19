<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\scctva;
use App\Support\SchoolScope;
use App\Support\WhatsappTagihan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AktifasiPembayaranBankController extends Controller
{
    private string $title = "Keuangan";
    private string $mainTitle = "Tagihan Siswa";
    private string $dataTitle = "Aktifasi Pembayaran Bank";

    public function index()
    {
        return view("admin.keuangan.tagihan_siswa.aktifasi_pembayaran_bank.index", [
            "title" => $this->title,
            "mainTitle" => $this->mainTitle,
            "dataTitle" => $this->dataTitle,
        ]);
    }

    public function tagihan($custid)
    {
        $siswa = scctcust::where("CUSTID", $custid)->first();
        if (!$siswa) {
            return response()->json(["message" => "Siswa tidak ditemukan."], 422);
        }

        $denied = SchoolScope::denyStudentMessage($siswa);
        if ($denied) {
            return response()->json(["message" => $denied], 403);
        }

        $nis = method_exists($siswa, "rawNis") ? $siswa->rawNis() : trim((string) ($siswa->nocust ?? $siswa->NOCUST ?? ""));
        $novaClose = ($nis !== "" && $nis !== "-") ? scctcust::showVA($nis, 0) : "";
        $novaOpen = ($nis !== "" && $nis !== "-") ? scctcust::showVA($nis, 1) : "";

        $bills = scctbill::query()
            ->where("CUSTID", $siswa->CUSTID)
            ->where("FSTSBolehBayar", 1)
            ->where(function ($q) {
                $sisaExpr = "CAST(COALESCE(PAYMENTLEFT, BILLAM - COALESCE(BILLPAID, 0), 0) AS SIGNED)";
                $q->where("PAIDST", 0)
                    ->orWhereNull("PAIDST")
                    ->orWhereRaw("{$sisaExpr} > 0");
            })
            ->orderBy("FUrutan")
            ->orderBy("AA")
            ->get();

        $rows = $bills->map(function ($bill) use ($nis) {
            $total = (int) ($bill->BILLAM ?? 0);
            $paid = (int) ($bill->BILLPAID ?? 0);
            $sisa = $bill->PAYMENTLEFT;
            if ($sisa === null || $sisa === "") {
                $sisa = max(0, $total - $paid);
            } else {
                $sisa = max(0, (int) $sisa);
            }
            $installable = (int) ($bill->isINSTALLABLE ?? 0) === 1;
            $attrs = $bill->getAttributes();
            $exp = $attrs["ExpDate"] ?? $attrs["expdate"] ?? null;
            $expDisplay = null;
            if ($exp && !preg_match('/^0{4}-0{2}-0{2}/', (string) $exp)) {
                try {
                    $expDisplay = Carbon::parse($exp)->format("Y-m-d");
                } catch (\Throwable) {
                    $expDisplay = null;
                }
            }

            return [
                "AA" => (int) $bill->AA,
                "BILLCD" => $bill->BILLCD,
                "nama_tagihan" => $bill->BILLNM,
                "total_tagihan" => $total,
                "sudah_dibayar" => max(0, $total - $sisa),
                "sisa_tagihan" => $sisa,
                "isINSTALLABLE" => $installable ? 1 : 0,
                "nova" => ($nis !== "" && $nis !== "-") ? scctcust::showVA($nis, $installable ? 1 : 0) : "",
                "va_type" => $installable ? "open" : "close",
                "exp_date" => $expDisplay,
                "tahun_akademik" => $bill->BTA,
                "periode" => $bill->BILLAC,
            ];
        })->values();

        $aktifasi = null;
        $vaAktif = scctva::query()
            ->where("CUSTID", $siswa->CUSTID)
            ->where("STATUS", 1)
            ->whereNotNull("SHARE_TOKEN")
            ->where("SHARE_TOKEN", "!=", "")
            ->orderByDesc("ID")
            ->first();

        if ($vaAktif) {
            $aktifasi = $this->formatAktifasiPayload($vaAktif, $siswa);
        }

        return response()->json([
            "siswa" => [
                "CUSTID" => $siswa->CUSTID,
                "nocust" => $siswa->nocust ?? $siswa->NOCUST,
                "nmcust" => $siswa->nmcust ?? $siswa->NMCUST,
                "kelas" => trim(($siswa->DESC02 ?? "") . " " . ($siswa->DESC03 ?? "")),
                "unit" => $siswa->CODE02,
                "angkatan" => $siswa->DESC04,
                "no_wa" => $siswa->NO_WA,
                "nova" => $novaClose,
                "nova_close" => $novaClose,
                "nova_open" => $novaOpen,
            ],
            "tagihan" => $rows,
            "aktifasi" => $aktifasi,
        ]);
    }

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "custid" => ["required"],
            "items" => ["required", "array", "min:1"],
            "items.*.AA" => ["required"],
            "items.*.amount" => ["required", "numeric", "min:1"],
        ]);

        if ($validator->fails()) {
            return response()->json([
                "message" => $validator->errors()->first(),
                "errors" => $validator->errors(),
            ], 422);
        }

        $siswa = scctcust::where("CUSTID", $request->custid)->first();
        if (!$siswa) {
            return response()->json(["message" => "Siswa tidak ditemukan."], 422);
        }

        $denied = SchoolScope::denyStudentMessage($siswa);
        if ($denied) {
            return response()->json(["message" => $denied], 403);
        }

        $nis = method_exists($siswa, "rawNis") ? $siswa->rawNis() : trim((string) ($siswa->nocust ?? $siswa->NOCUST ?? ""));
        if ($nis === "" || $nis === "-") {
            return response()->json(["message" => "Siswa tidak memiliki NIS untuk nomor VA."], 422);
        }

        $requested = collect($request->items)
            ->map(fn ($item) => [
                "AA" => (int) $item["AA"],
                "amount" => (int) $item["amount"],
            ])
            ->unique("AA")
            ->values();

        $bills = scctbill::query()
            ->where("CUSTID", $siswa->CUSTID)
            ->where("FSTSBolehBayar", 1)
            ->whereIn("AA", $requested->pluck("AA"))
            ->get()
            ->keyBy("AA");

        if ($bills->count() !== $requested->count()) {
            return response()->json(["message" => "Ada tagihan yang tidak valid atau tidak boleh dibayar."], 422);
        }

        $pairs = [];
        $expDates = [];
        $installableFlags = [];
        foreach ($requested as $item) {
            $bill = $bills->get($item["AA"]);
            $total = (int) ($bill->BILLAM ?? 0);
            $paid = (int) ($bill->BILLPAID ?? 0);
            $sisa = $bill->PAYMENTLEFT;
            if ($sisa === null || $sisa === "") {
                $sisa = max(0, $total - $paid);
            } else {
                $sisa = max(0, (int) $sisa);
            }

            if ($sisa <= 0) {
                return response()->json(["message" => "Tagihan {$bill->BILLNM} sudah lunas."], 422);
            }

            $amount = (int) $item["amount"];
            $installable = (int) ($bill->isINSTALLABLE ?? 0) === 1;
            $installableFlags[] = $installable ? 1 : 0;
            if ($amount <= 0 || $amount > $sisa) {
                return response()->json([
                    "message" => "Nominal bayar untuk {$bill->BILLNM} tidak valid (max Rp " . number_format($sisa, 0, ",", ".") . ").",
                ], 422);
            }
            if (!$installable && $amount !== $sisa) {
                return response()->json([
                    "message" => "Tagihan {$bill->BILLNM} tidak dapat dicicil. Nominal harus Rp " . number_format($sisa, 0, ",", ".") . ".",
                ], 422);
            }

            $pairs[] = [
                "AA" => (int) $bill->AA,
                "nama" => (string) $bill->BILLNM,
                "amount" => $amount,
            ];

            $exp = $bill->getAttributes()["ExpDate"]
                ?? $bill->getAttributes()["expdate"]
                ?? null;
            if ($exp && !preg_match('/^0{4}-0{2}-0{2}/', (string) $exp)) {
                try {
                    $expDates[] = Carbon::parse($exp);
                } catch (\Throwable) {
                }
            }
        }

        $uniqueFlags = array_values(array_unique($installableFlags));
        if (count($uniqueFlags) > 1) {
            return response()->json([
                "message" => "Tidak bisa digabung: pilih tagihan dengan jenis VA yang sama. VA Close (tidak cicil) dan VA Open (cicil) harus diaktifkan terpisah.",
            ], 422);
        }

        $isNyicil = (int) ($uniqueFlags[0] ?? 0);
        $novaFull = scctcust::showVA($nis, $isNyicil);

        $total = array_sum(array_column($pairs, "amount"));
        $arrayTagihan = implode(",", array_column($pairs, "AA"));
        $billam = implode(",", array_column($pairs, "amount"));
        $expDate = collect($expDates)->sort()->first();
        $token = scctva::generateShareToken();
        $nama = (string) ($siswa->nmcust ?? $siswa->NMCUST ?? "");

        try {
            DB::connection("DATA_MYSQL")->beginTransaction();
            scctva::deactivateForStudent($nis, $siswa->CUSTID);

            scctva::query()->create([
                "CUSTID" => $siswa->CUSTID,
                "NOCUST" => $nis,
                "NMCUST" => $nama,
                "NOVA" => $novaFull,
                "ArrayTagihan" => $arrayTagihan,
                "BILLAM" => $billam,
                "BILLTOT" => $total,
                "STATUS" => 1,
                "CREATED_AT" => now()->format("Y-m-d H:i:s"),
                "ExpDate" => $expDate ? $expDate->format("Y-m-d H:i:s") : null,
                "SHARE_TOKEN" => $token,
            ]);

            DB::connection("DATA_MYSQL")->commit();
        } catch (\Throwable $e) {
            DB::connection("DATA_MYSQL")->rollBack();

            return response()->json([
                "message" => "Gagal mengaktifkan pembayaran bank: " . $e->getMessage(),
                "error" => $e->getMessage(),
            ], 422);
        }

        $va = scctva::query()->where("SHARE_TOKEN", $token)->first();
        if (!$va) {
            return response()->json([
                "message" => "Aktifasi tersimpan, tetapi data tidak dapat dibaca ulang. Coba muat tagihan siswa lagi.",
            ], 422);
        }

        return response()->json([
            "message" => $isNyicil === 1
                ? "Pembayaran bank berhasil diaktifkan (VA Open / cicil)."
                : "Pembayaran bank berhasil diaktifkan (VA Close).",
            "data" => $this->formatAktifasiPayload($va, $siswa, $pairs),
            "va_type" => $isNyicil === 1 ? "open" : "close",
        ]);
    }

    public function pdfByToken(string $token)
    {
        $va = scctva::query()->where("SHARE_TOKEN", $token)->first();
        if (!$va) {
            abort(404, "Data aktifasi tidak ditemukan.");
        }

        $siswa = scctcust::where("CUSTID", $va->CUSTID)->first();
        if ($siswa && request()->user()) {
            $denied = SchoolScope::denyStudentMessage($siswa);
            if ($denied) {
                abort(403, $denied);
            }
        }

        return $this->streamPdf($va, $siswa);
    }

    public function pdf($id)
    {
        $va = scctva::query()->where("ID", $id)->first();
        if (!$va) {
            abort(404, "Data aktifasi tidak ditemukan.");
        }

        $siswa = scctcust::where("CUSTID", $va->CUSTID)->first();
        if ($siswa) {
            $denied = SchoolScope::denyStudentMessage($siswa);
            if ($denied) {
                abort(403, $denied);
            }
        }

        return $this->streamPdf($va, $siswa);
    }

    private function streamPdf(scctva $va, ?scctcust $siswa = null)
    {
        try {
            $payload = $this->buildSharePayload($va, $siswa);
            $pdf = Pdf::loadView("cetak.cara-bayar-va", $payload)->setPaper("a4", "portrait");
            $filename = "cara-bayar-" . ($payload["nocust"] ?: "va") . ".pdf";

            return $pdf->download($filename);
        } catch (\Throwable $e) {
            report($e);
            abort(500, "Gagal membuat PDF: " . $e->getMessage());
        }
    }

    public function buildSharePayload(scctva $va, ?scctcust $siswa = null): array
    {
        $siswa ??= scctcust::where("CUSTID", $va->CUSTID)->first();
        $items = $va->parsedItems();
        $nis = trim((string) ($va->NOCUST ?? ""));
        $nama = trim((string) ($va->NMCUST ?? ($siswa->nmcust ?? $siswa->NMCUST ?? "")));
        $kelas = $siswa
            ? trim(($siswa->DESC02 ?? "") . " - " . ($siswa->DESC03 ?? ""), " -")
            : "";

        $expDisplay = null;
        if (!empty($va->ExpDate) && !preg_match('/^0{4}-0{2}-0{2}/', (string) $va->ExpDate)) {
            try {
                $expDisplay = Carbon::parse($va->ExpDate)->format("Y-m-d");
            } catch (\Throwable) {
                $expDisplay = null;
            }
        }

        return [
            "sekolah" => config("app.nama_instansi", config("app.name")),
            "app_name" => config("app.name"),
            "logo" => config("app.logo"),
            "nama" => $nama,
            "nocust" => $nis,
            "kelas" => $kelas,
            "unit" => $siswa->CODE02 ?? "",
            "nova" => $va->displayNova(),
            "total" => (int) ($va->BILLTOT ?? 0),
            "items" => $items,
            "exp_date" => $expDisplay,
            "created_at" => $va->CREATED_AT,
            "share_url" => $va->shareUrl(),
            "pdf_url" => $this->pdfUrlForToken((string) $va->SHARE_TOKEN),
        ];
    }

    private function formatAktifasiPayload(scctva $va, ?scctcust $siswa = null, ?array $pairs = null): array
    {
        $siswa ??= scctcust::where("CUSTID", $va->CUSTID)->first();
        $items = $pairs ?? $va->parsedItems();
        $nis = trim((string) ($va->NOCUST ?? ""));
        $nama = trim((string) ($va->NMCUST ?? ($siswa->nmcust ?? $siswa->NMCUST ?? "")));
        $novaDisplay = $va->displayNova();
        $token = (string) ($va->SHARE_TOKEN ?? "");
        $shareUrl = $token !== "" ? url("/cara-bayar/" . $token) : null;
        $pdfUrl = $token !== "" ? $this->pdfUrlForToken($token) : null;

        $expDate = null;
        $expDisplay = null;
        if (!empty($va->ExpDate) && !preg_match('/^0{4}-0{2}-0{2}/', (string) $va->ExpDate)) {
            try {
                $expDate = Carbon::parse($va->ExpDate);
                $expDisplay = $expDate->format("Y-m-d");
            } catch (\Throwable) {
            }
        }

        $total = (int) ($va->BILLTOT ?? array_sum(array_column($items, "amount")));
        $message = $this->buildWaMessage($nama, $nis, $novaDisplay, $items, $total, (string) $shareUrl, $expDate);
        $waUrl = WhatsappTagihan::waMeUrl($siswa->NO_WA ?? null, $message);

        return [
            "id" => $va->getKey() ?: $va->ID,
            "token" => $token,
            "nova" => $novaDisplay,
            "share_url" => $shareUrl,
            "pdf_url" => $pdfUrl,
            "wa_url" => $waUrl,
            "wa_message" => $message,
            "total" => $total,
            "exp_date" => $expDisplay,
            "created_at" => $va->CREATED_AT,
            "items" => array_map(function ($item) {
                return [
                    "AA" => (int) ($item["aa"] ?? $item["AA"] ?? 0),
                    "nama" => (string) ($item["nama"] ?? ""),
                    "amount" => (int) ($item["amount"] ?? 0),
                ];
            }, $items),
            "siswa" => [
                "nama" => $nama,
                "nocust" => $nis,
                "no_wa" => $siswa->NO_WA ?? null,
            ],
        ];
    }

    private function pdfUrlForToken(string $token): string
    {
        return url("/cara-bayar/" . $token . "/pdf");
    }

    private function buildWaMessage(
        string $nama,
        string $nis,
        string $nova,
        array $pairs,
        int $total,
        string $shareUrl,
        ?Carbon $expDate = null,
    ): string {
        $nl = "\n";
        $lines = [];
        $lines[] = "Assalamu'alaikum wr. wb.";
        $lines[] = "";
        $lines[] = "Yth. Bapak/Ibu Orang Tua/Wali";
        $lines[] = "Siswa: *{$nama}*";
        $lines[] = "NIS: {$nis}";
        $lines[] = "";
        $lines[] = "Berikut rincian tagihan yang siap dibayar melalui Virtual Account:";
        $lines[] = "";

        foreach ($pairs as $i => $pair) {
            $no = $i + 1;
            $namaTagihan = (string) ($pair["nama"] ?? "-");
            $nominal = WhatsappTagihan::formatRupiah($pair["amount"] ?? 0);
            $lines[] = "{$no}. {$namaTagihan}";
            $lines[] = "    Rp {$nominal}";
        }

        $lines[] = "";
        $lines[] = "------------------------------";
        $lines[] = "*Total bayar: Rp " . WhatsappTagihan::formatRupiah($total) . "*";
        $lines[] = "*Nomor VA: {$nova}*";
        if ($expDate) {
            $lines[] = "Batas waktu: " . $expDate->format("d-m-Y");
        }
        $lines[] = "------------------------------";
        $lines[] = "";
        $lines[] = "Petunjuk lengkap cara bayar:";
        $lines[] = $shareUrl;
        $lines[] = "";
        $lines[] = "Mohon pastikan nama dan nominal sudah sesuai sebelum konfirmasi pembayaran.";
        $lines[] = "";
        $lines[] = "Terima kasih.";

        return implode($nl, $lines);
    }
}
