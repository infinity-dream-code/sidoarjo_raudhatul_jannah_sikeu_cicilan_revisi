<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instruksi Pembayaran VA | {{ $sekolah ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #14231c;
            --muted: #5c6b63;
            --line: #cfd8d2;
            --paper: #fbfcfa;
            --green: #1b5e3b;
            --green-deep: #0f3d27;
            --green-soft: #e8f2ec;
            --amber: #8a6a1f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
            background:
                repeating-linear-gradient(
                    -12deg,
                    transparent,
                    transparent 18px,
                    rgba(27, 94, 59, 0.015) 18px,
                    rgba(27, 94, 59, 0.015) 19px
                ),
                linear-gradient(180deg, #eef3ef 0%, #f7f8f6 45%, #ebeee9 100%);
            min-height: 100vh;
        }
        .page {
            max-width: 740px;
            margin: 0 auto;
            padding: 28px 14px 48px;
        }
        .sheet {
            background: var(--paper);
            border: 1px solid var(--line);
            box-shadow: 0 1px 0 rgba(20, 35, 28, 0.04);
        }
        .letterhead {
            padding: 22px 28px 18px;
            border-bottom: 3px solid var(--green);
            display: grid;
            grid-template-columns: 64px 1fr;
            gap: 14px;
            align-items: center;
        }
        .logo {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border: 1px solid var(--line);
            background: #fff;
        }
        .school-name {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: 1.55rem;
            font-weight: 700;
            line-height: 1.15;
            color: var(--green-deep);
            margin: 0;
            letter-spacing: 0.01em;
        }
        .school-meta {
            margin-top: 4px;
            font-size: 0.8rem;
            color: var(--muted);
            line-height: 1.4;
        }
        .doc-body { padding: 22px 28px 28px; }
        .doc-title {
            margin: 0 0 6px;
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--green-deep);
        }
        .doc-sub {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 0.92rem;
            max-width: 36em;
            line-height: 1.5;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 0.92rem;
        }
        .meta-table th,
        .meta-table td {
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid #e4ebe6;
            vertical-align: top;
        }
        .meta-table th {
            width: 34%;
            color: var(--muted);
            font-weight: 500;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .meta-table td {
            font-weight: 600;
        }
        .va-block {
            border: 2px solid var(--green);
            background: var(--green-soft);
            padding: 16px 18px;
            margin: 0 0 22px;
        }
        .va-label {
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--green-deep);
            font-weight: 600;
            margin-bottom: 6px;
        }
        .va-number {
            font-family: "IBM Plex Mono", ui-monospace, monospace;
            font-size: clamp(1.25rem, 4vw, 1.75rem);
            font-weight: 600;
            color: var(--green-deep);
            letter-spacing: 0.04em;
            word-break: break-all;
            line-height: 1.25;
        }
        .va-actions {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .btn {
            appearance: none;
            border: 1px solid var(--green);
            background: var(--green);
            color: #fff;
            font: inherit;
            font-size: 0.86rem;
            font-weight: 600;
            padding: 9px 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-ghost {
            background: transparent;
            color: var(--green-deep);
        }
        .btn:hover { filter: brightness(0.96); }
        .section-label {
            margin: 0 0 8px;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
        }
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 0.92rem;
        }
        .bill-table th,
        .bill-table td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }
        .bill-table th {
            background: #f0f4f1;
            color: var(--green-deep);
            font-size: 0.74rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .bill-table .num { text-align: right; white-space: nowrap; }
        .bill-table tfoot td {
            border-bottom: 0;
            border-top: 2px solid var(--green-deep);
            font-weight: 700;
            padding-top: 12px;
        }
        .steps {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px dashed var(--line);
        }
        .steps h2 {
            margin: 0 0 10px;
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: 1.35rem;
            color: var(--green-deep);
        }
        .steps ol {
            margin: 0;
            padding-left: 1.2rem;
            color: var(--ink);
        }
        .steps li {
            margin: 0 0 8px;
            line-height: 1.5;
            padding-left: 4px;
        }
        .footnote {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.55;
        }
        .toast {
            display: none;
            margin-top: 8px;
            color: var(--green);
            font-size: 0.82rem;
            font-weight: 600;
        }
        @media (max-width: 600px) {
            .letterhead, .doc-body { padding-left: 16px; padding-right: 16px; }
            .letterhead { grid-template-columns: 52px 1fr; }
            .logo { width: 52px; height: 52px; }
            .school-name { font-size: 1.25rem; }
            .doc-title { font-size: 1.4rem; }
            .meta-table th { width: 40%; }
        }
        @media print {
            body { background: #fff; }
            .page { padding: 0; }
            .sheet { box-shadow: none; border: 0; }
            .va-actions { display: none; }
        }
    </style>
</head>
<body>
@php
    $logoFile = public_path($logo ?? 'icon-jannah.jpeg');
    $logoUrl = is_file($logoFile) ? asset($logo ?? 'icon-jannah.jpeg') : null;
    $alamat = config('app.alamat');
@endphp
<div class="page">
    <article class="sheet">
        <header class="letterhead">
            @if($logoUrl)
                <img class="logo" src="{{ $logoUrl }}" alt="Logo">
            @else
                <div class="logo" aria-hidden="true"></div>
            @endif
            <div>
                <h1 class="school-name">{{ str_replace('_', ' ', $sekolah ?? $app_name ?? 'Raudlatul Jannah') }}</h1>
                @if($alamat)
                    <div class="school-meta">{{ $alamat }}</div>
                @endif
            </div>
        </header>

        <div class="doc-body">
            <h2 class="doc-title">Instruksi Pembayaran VA</h2>
            <p class="doc-sub">
                Dokumen ini memuat nomor Virtual Account dan rincian tagihan yang telah diaktifkan
                oleh bagian keuangan. Gunakan nomor VA di bawah untuk pembayaran.
            </p>

            <table class="meta-table">
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

            <div class="va-block">
                <div class="va-label">Nomor Virtual Account</div>
                <div class="va-number" id="nova-text">{{ $nova ?: '-' }}</div>
                <div class="va-actions">
                    <button type="button" class="btn" id="btn-copy-va">Salin nomor VA</button>
                    @if(!empty($pdf_url))
                        <a class="btn btn-ghost" href="{{ $pdf_url }}">Unduh PDF</a>
                    @endif
                </div>
                <div class="toast" id="copy-toast">Nomor VA disalin.</div>
            </div>

            <div class="section-label">Rincian tagihan</div>
            <table class="bill-table">
                <thead>
                <tr>
                    <th style="width:42px">No</th>
                    <th>Uraian</th>
                    <th class="num">Nominal</th>
                </tr>
                </thead>
                <tbody>
                @forelse($items as $i => $item)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $item['nama'] ?? '-' }}</td>
                        <td class="num">Rp {{ number_format((int) ($item['amount'] ?? 0), 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Tidak ada rincian tagihan.</td>
                    </tr>
                @endforelse
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="2">Total pembayaran</td>
                    <td class="num">Rp {{ number_format((int) ($total ?? 0), 0, ',', '.') }}</td>
                </tr>
                </tfoot>
            </table>

            <section class="steps">
                <h2>Cara bayar</h2>
                <ol>
                    <li>Buka mobile banking, ATM, atau teller bank yang mendukung Virtual Account.</li>
                    <li>Pilih menu Transfer / Pembayaran Virtual Account.</li>
                    <li>Masukkan nomor VA: <strong>{{ $nova ?: '-' }}</strong></li>
                    <li>Periksa nama siswa dan nominal, lalu konfirmasi pembayaran.</li>
                    <li>Simpan bukti transfer sebagai arsip.</li>
                </ol>
            </section>

            <p class="footnote">
                Jika nominal atau nomor VA tidak sesuai, hubungi bagian keuangan sekolah sebelum melakukan pembayaran.
                @if(!empty($created_at))
                    Dokumen dibuat: {{ $created_at }}.
                @endif
            </p>
        </div>
    </article>
</div>
<script>
    document.getElementById('btn-copy-va')?.addEventListener('click', async function () {
        const text = document.getElementById('nova-text')?.textContent?.trim() || '';
        if (!text || text === '-') return;
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            window.prompt('Salin nomor VA:', text);
        }
        const toast = document.getElementById('copy-toast');
        if (toast) {
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 1800);
        }
    });
</script>
</body>
</html>
