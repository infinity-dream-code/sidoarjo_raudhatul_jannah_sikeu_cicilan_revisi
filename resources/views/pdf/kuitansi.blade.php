@extends('layouts.export.kop_file')
@php
    use Carbon\Carbon;
    $documentTitle = 'KUITANSI';
    $hideDefaultFooter = true;

    $nis = $siswa->NOCUST ?? null;
    $hasNis = $nis && $nis !== '-';
    $nova = $hasNis ? \App\Models\scctcust::showVA($nis) : null;
    $total = (int) collect($tagihans ?? [])->sum(fn ($t) => (int) ($t->BILLAM ?? $t->BILLPAID ?? 0));
    $terbilang = \App\Support\Terbilang::rupiah($total);

    $kelas = trim((string) ($siswa->DESC02 ?? ''));
    $kelompok = trim((string) ($siswa->DESC03 ?? ''));
    $kelasLabel = $kelas !== '' && $kelompok !== ''
        ? ($kelas . '-' . $kelompok)
        : trim($kelas . ' ' . $kelompok);

    $paidAt = null;
    foreach ($tagihans ?? [] as $t) {
        if (!empty($t->PAIDDT)) {
            $paidAt = $t->PAIDDT;
            break;
        }
    }
    $paidLabel = $paidAt
        ? Carbon::parse($paidAt)->translatedFormat('d F Y')
        : Carbon::now()->translatedFormat('d F Y');

    $metode = '-';
    if (!empty($tagihans[0]->FIDBANK ?? null) || !empty($tagihans[0]->NOREFF ?? null)) {
        $metode = \App\Support\PaymentEmailPayload::resolvePaymentMethodLabel(
            isset($tagihans[0]->FIDBANK) ? (string) $tagihans[0]->FIDBANK : null,
            isset($tagihans[0]->NOREFF) ? (string) $tagihans[0]->NOREFF : null,
        );
    }
@endphp
@section('title', 'Kuitansi Pembayaran')
@section('content')
    <table width="100%" class="main-table" style="margin-bottom:12px;">
        <tr>
            <td class="info-label">Nama Siswa</td>
            <td>: <strong>{{ $siswa->NMCUST ?? '-' }}</strong></td>
            <td class="info-label">No. VA</td>
            <td>: <strong>{{ $nova ?? '-' }}</strong></td>
        </tr>
        <tr>
            <td class="info-label">Kelas</td>
            <td>: <strong>{{ $kelasLabel !== '' ? $kelasLabel : '-' }}</strong></td>
            <td class="info-label">Metode Pembayaran</td>
            <td>: <strong>{{ $metode }}</strong></td>
        </tr>
        <tr>
            <td class="info-label">Jenjang</td>
            <td>: <strong>{{ $siswa->CODE02 ?? '-' }}</strong></td>
            <td class="info-label">Tanggal Pembayaran</td>
            <td>: <strong>{{ $paidLabel }}</strong></td>
        </tr>
    </table>

    <table width="100%" class="table-border main-table">
        <thead style="background-color:#ededed;">
        <tr>
            <th style="width:8%;">No.</th>
            <th>Uraian</th>
            <th style="width:28%;" class="text-end">Nominal</th>
        </tr>
        </thead>
        <tbody>
        @foreach($tagihans as $tagihan)
            <tr>
                <td align="center">{{ $loop->iteration }}</td>
                <td>{{ $tagihan->BILLNM }}</td>
                <td align="right">@rupiah($tagihan->BILLAM)</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table width="100%" class="main-table" style="margin-top:12px;">
        <tr>
            <td></td>
            <td align="right" style="width:35%;">
                <strong>Total Pembayaran</strong>
                &nbsp;&nbsp;
                <strong style="border-bottom:1px solid #000;padding-bottom:2px;">@rupiah($total)</strong>
            </td>
        </tr>
    </table>

    <table width="100%" class="main-table" style="margin-top:18px;">
        <tr>
            <td style="vertical-align:top;width:65%;">
                <div class="terbilang-box">{{ $terbilang }}</div>
            </td>
            <td align="center" style="vertical-align:top;">
                <div>Diterima Oleh,</div>
                <div style="height:48px;"></div>
                <div><strong>ADMIN</strong></div>
            </td>
        </tr>
    </table>
@endsection
