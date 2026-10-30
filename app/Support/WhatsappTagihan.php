<?php

namespace App\Support;

use App\Models\WaTemplate;
use Throwable;

class WhatsappTagihan
{
    public const KODE_TAGIHAN = "tagihan";

    public static function placeholders(): array
    {
        return [
            "{nama}" => "Nama siswa",
            "{nis}" => "NIS",
            "{no_daftar}" => "No pendaftaran",
            "{kelas}" => "Kelas",
            "{kelompok}" => "Kelompok",
            "{unit}" => "Unit",
            "{nama_tagihan}" => "Nama tagihan",
            "{periode}" => "Periode",
            "{jumlah_tagihan}" => "Jumlah tagihan",
            "{terbayar}" => "Jumlah terbayar",
            "{sisa_tagihan}" => "Sisa tagihan",
            "{rincian}" => "Daftar rincian tagihan",
            "{no_va}" => "Nomor VA",
        ];
    }

    public static function defaultTemplate(): string
    {
        return "Assalamu'alaikum wr. wb.\n\n"
            . "Yth. Wali siswa *{nama}*\n"
            . "NIS: {nis}\n"
            . "Kelas: {kelas}\n\n"
            . "Kami informasikan tagihan:\n"
            . "*{nama_tagihan}*\n"
            . "Periode: {periode}\n\n"
            . "Jumlah tagihan: Rp {jumlah_tagihan}\n"
            . "Sudah dibayar: Rp {terbayar}\n"
            . "Sisa tagihan: Rp {sisa_tagihan}\n\n"
            . "Mohon dapat segera diselesaikan. Terima kasih.";
    }

    /**
     * Template aktif + baris nominal (jumlah/terbayar/sisa) yang belum ada di template
     * ditambahkan otomatis agar pesan tagihan selalu memuat rincian nominal.
     */
    public static function activeTemplateWithAmounts(?string $kode = self::KODE_TAGIHAN): string
    {
        $template = rtrim(self::activeTemplate($kode));

        $lines = [];
        if (!str_contains($template, '{jumlah_tagihan}')) {
            $lines[] = "Jumlah tagihan: Rp {jumlah_tagihan}";
        }
        if (!str_contains($template, '{terbayar}')) {
            $lines[] = "Sudah dibayar: Rp {terbayar}";
        }
        if (!str_contains($template, '{sisa_tagihan}')) {
            $lines[] = "Sisa tagihan: Rp {sisa_tagihan}";
        }

        return $lines === [] ? $template : $template . "\n\n" . implode("\n", $lines);
    }

    /**
     * @return array{total: int, terbayar: int, sisa: int}
     */
    public static function amounts(mixed $billAm, mixed $billPaid, mixed $paymentLeft, mixed $paidSt = 0): array
    {
        $total = max(0, (int) ($billAm ?? 0));
        if ($paymentLeft === null || $paymentLeft === '') {
            $sisa = (int) $paidSt === 1 ? 0 : max(0, $total - (int) ($billPaid ?? 0));
        } else {
            $sisa = max(0, (int) $paymentLeft);
        }

        return [
            'total' => $total,
            'terbayar' => max(0, $total - $sisa),
            'sisa' => $sisa,
        ];
    }

    public static function activeTemplate(?string $kode = self::KODE_TAGIHAN): string
    {
        try {
            $query = WaTemplate::query()->where("is_active", 1);

            if ($kode) {
                $byKode = (clone $query)->where("kode", $kode)->value("template");
                if (is_string($byKode) && trim($byKode) !== "") {
                    return $byKode;
                }
            }

            $any = $query->orderBy("id")->value("template");
            if (is_string($any) && trim($any) !== "") {
                return $any;
            }
        } catch (Throwable) {
            // Tabel belum ada / belum di-create di Navicat.
        }

        return self::defaultTemplate();
    }

    public static function formatPhone(?string $raw): ?string
    {
        $digits = preg_replace("/\D+/", "", (string) $raw);
        if ($digits === null || $digits === "") {
            return null;
        }

        if (str_starts_with($digits, "0")) {
            $digits = "62" . substr($digits, 1);
        } elseif (str_starts_with($digits, "8")) {
            $digits = "62" . $digits;
        }

        if (!str_starts_with($digits, "62") || strlen($digits) < 10) {
            return null;
        }

        return $digits;
    }

    public static function formatPhoneGateway(?string $raw): ?string
    {
        $digits = self::formatPhone($raw);

        return $digits === null ? null : '+' . $digits;
    }

    public static function templateById(?int $id): ?string
    {
        if (!$id) {
            return self::defaultTemplate();
        }

        try {
            $tpl = WaTemplate::query()
                ->where('id', $id)
                ->where('is_active', 1)
                ->value('template');

            if (is_string($tpl) && trim($tpl) !== '') {
                return $tpl;
            }
        } catch (Throwable) {
            // Tabel belum ada.
        }

        return null;
    }

    public static function withRincianPlaceholder(string $template): string
    {
        if (str_contains($template, '{rincian}')) {
            return $template;
        }

        return rtrim($template) . "\n\nRincian:{rincian}";
    }

    /**
     * @param  iterable<mixed>  $bills
     */
    public static function rincian(iterable $bills): string
    {
        $lines = [];

        foreach ($bills as $bill) {
            $nama = trim((string) self::billValue($bill, 'BILLNM', '-'));
            $periode = trim((string) self::billValue($bill, 'BILLAC', ''));
            $amounts = self::amounts(
                self::billValue($bill, 'BILLAM', 0),
                self::billValue($bill, 'BILLPAID', 0),
                self::billValue($bill, 'PAYMENTLEFT', null),
                self::billValue($bill, 'PAIDST', 0)
            );
            $label = $periode !== '' ? $nama . ' (' . $periode . ')' : $nama;
            $lines[] = '- ' . $label . ': Rp *' . self::formatRupiah($amounts['sisa']) . '*';
        }

        return $lines === [] ? '-' : "\n" . implode("\n", $lines);
    }

    private static function billValue(mixed $bill, string $key, mixed $default = null): mixed
    {
        if (is_array($bill)) {
            return $bill[$key] ?? $default;
        }

        if (is_object($bill)) {
            return $bill->{$key} ?? $default;
        }

        return $default;
    }

    public static function formatRupiah(mixed $value): string
    {
        return number_format((float) $value, 0, ",", ".");
    }

    public static function applyTemplate(string $template, array $vars): string
    {
        $repl = [];
        foreach ($vars as $key => $value) {
            $repl["{" . $key . "}"] = (string) ($value ?? "-");
        }

        return strtr($template, $repl);
    }

    public static function waMeUrl(?string $phone, string $message): ?string
    {
        $formatted = self::formatPhone($phone);
        if (!$formatted) {
            return null;
        }

        return "https://wa.me/" . $formatted . "?text=" . rawurlencode($message);
    }
}
