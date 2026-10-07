<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Instruksi Pembayaran VA - {{ $nocust ?? '' }}</title>
    <style>
        @page { margin: 28px 32px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #14231c;
            line-height: 1.4;
        }
        .rule {
            border: 0;
            border-top: 3px solid #1b5e3b;
            margin: 10px 0 16px;
        }
        .letterhead {
            width: 100%;
            border-collapse: collapse;
        }
        .letterhead td { vertical-align: middle; }
        .logo {
            width: 58px;
            height: 58px;
            border: 1px solid #cfd8d2;
        }
        .school-name {
            font-family: DejaVu Serif, serif;
            font-size: 16px;
            font-weight: bold;
            color: #0f3d27;
            margin: 0 0 3px;
        }
        .school-meta {
            font-size: 9px;
            color: #5c6b63;
        }
        .doc-title {
            font-family: DejaVu Serif, serif;
            font-size: 15px;
            color: #0f3d27;
            margin: 0 0 4px;
        }
        .doc-sub {
            font-size: 10px;
            color: #5c6b63;
            margin: 0 0 14px;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .meta th, .meta td {
            text-align: left;
            padding: 6px 0;
            border-bottom: 1px solid #e4ebe6;
            font-size: 10.5px;
        }
        .meta th {
            width: 32%;
            color: #5c6b63;
            font-weight: normal;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 9px;
        }
        .meta td { font-weight: bold; }
        .va-box {
            border: 2px solid #1b5e3b;
            background: #e8f2ec;
            padding: 12px 14px;
            margin: 0 0 16px;
        }
        .va-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #0f3d27;
            margin-bottom: 4px;
        }
        .va-number {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 18px;
            font-weight: bold;
            color: #0f3d27;
            letter-spacing: 1px;
        }
        .section {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #5c6b63;
            margin: 0 0 6px;
        }
        .bills {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .bills th, .bills td {
            border-bottom: 1px solid #cfd8d2;
            padding: 7px 6px;
            font-size: 10.5px;
            text-align: left;
        }
        .bills th {
            background: #f0f4f1;
            color: #0f3d27;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .right { text-align: right; }
        .bills tfoot td {
            border-bottom: 0;
            border-top: 2px solid #0f3d27;
            font-weight: bold;
            padding-top: 9px;
        }
        .steps {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px dashed #cfd8d2;
        }
        .steps h2 {
            font-family: DejaVu Serif, serif;
            font-size: 13px;
            color: #0f3d27;
            margin: 0 0 6px;
        }
        .steps ol {
            margin: 0;
            padding-left: 16px;
        }
        .steps li {
            margin: 0 0 5px;
            font-size: 10.5px;
        }
        .foot {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #cfd8d2;
            font-size: 9px;
            color: #5c6b63;
        }
    </style>
</head>
<body>
@php
    $logoPath = public_path($logo ?? 'icon-jannah.jpeg');
    $hasLogo = is_file($logoPath);
    $alamat = config('app.alamat');
    $schoolLabel = str_replace('_', ' ', (string) ($sekolah ?? $app_name ?? 'Raudlatul Jannah'));
@endphp

<table class="letterhead">
    <tr>
        <td width="70">
            @if($hasLogo)
                <img class="logo" src="{{ $logoPath }}" alt="Logo">
            @endif
        </td>
        <td>
            <div class="school-name">{{ $schoolLabel }}</div>
            @if($alamat)
                <div class="school-meta">{{ $alamat }}</div>
            @endif
        </td>
    </tr>
</table>
<hr class="rule">

<div class="doc-title">Instruksi Pembayaran Virtual Account</div>
<div class="doc-sub">
    Gunakan nomor VA berikut untuk menyelesaikan tagihan yang diaktifkan bagian keuangan.
</div>

<table class="meta">
    <tr>
        <th>Nama siswa</th>
        <td>{{ $nama ?: '-' }}</td>
    </tr>
    <tr>
        <th>NIS</th>
        <td>{{ $nocust ?: '-' }}</td>
    </tr>
    <tr>
        <th>Kelas</th>
        <td>{{ $kelas ?: '-' }}</td>
    </tr>
    <tr>
        <th>Batas bayar</th>
        <td>{{ $exp_date ?: 'Tidak ditentukan' }}</td>
    </tr>
</table>

<div class="va-box">
    <div class="va-label">Nomor Virtual Account</div>
    <div class="va-number">{{ $nova ?: '-' }}</div>
</div>

<div class="section">Rincian tagihan</div>
<table class="bills">
    <thead>
    <tr>
        <th width="36">No</th>
        <th>Uraian</th>
        <th width="120" class="right">Nominal</th>
    </tr>
    </thead>
    <tbody>
    @foreach($items as $i => $item)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $item['nama'] ?? '-' }}</td>
            <td class="right">Rp {{ number_format((int) ($item['amount'] ?? 0), 0, ',', '.') }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
    <tr>
        <td colspan="2">Total pembayaran</td>
        <td class="right">Rp {{ number_format((int) ($total ?? 0), 0, ',', '.') }}</td>
    </tr>
    </tfoot>
</table>

<div class="steps">
    <h2>Cara bayar</h2>
    <ol>
        <li>Buka mobile banking / ATM / teller bank yang mendukung Virtual Account.</li>
        <li>Pilih menu Transfer / Pembayaran Virtual Account.</li>
        <li>Masukkan nomor VA: <strong>{{ $nova ?: '-' }}</strong></li>
        <li>Periksa nama siswa dan nominal, lalu konfirmasi pembayaran.</li>
        <li>Simpan bukti transfer sebagai arsip.</li>
    </ol>
</div>

<div class="foot">
    Jika nominal atau nomor VA tidak sesuai, hubungi bagian keuangan sekolah sebelum membayar.
    @if(!empty($created_at))
        Dibuat: {{ $created_at }}.
    @endif
</div>
</body>
</html>
