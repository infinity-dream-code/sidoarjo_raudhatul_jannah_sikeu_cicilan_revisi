@extends('layouts.export.kop_file')
@php
    $kartuMode = \App\Support\KartuSiswaRows::normalizeMode($kartuMode ?? 'all');
    // unpaid = Data Tagihan, paid = Data Tagihan Lunas, all = campur (Data Pembayaran/rekap)
    $documentTitle = match ($kartuMode) {
        'unpaid' => 'DATA TAGIHAN SISWA',
        'paid' => 'KARTU LUNAS',
        default => 'KARTU SISWA',
    };
    if (trim((string) ($documentTitleOverride ?? '')) !== '') {
        $documentTitle = $documentTitleOverride;
    }
    $kartuRows = \App\Support\KartuSiswaRows::build($tagihans ?? [], $kartuMode);
    $sumTotal = array_sum(array_column($kartuRows, 'total'));
    $sumPaid = array_sum(array_column($kartuRows, 'terbayar'));
    $sumSisa = array_sum(array_column($kartuRows, 'sisa'));
    $isKartuSiswa = $kartuMode === 'all';
    $isKartuLunas = $kartuMode === 'paid';
    $isDataTagihan = $kartuMode === 'unpaid';
    $showStatus = $isKartuSiswa || $isDataTagihan;
    $colCount = ($isKartuSiswa || $isKartuLunas || $isDataTagihan) ? 5 : 4;

    $nisForVa = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-' && is_numeric($siswa->NOCUST))
        ? $siswa->NOCUST
        : ($siswa->NUM2ND ?? '');
    if (!isset($nova) || $nova === '' || $nova === null) {
        $nova = ($nisForVa && $nisForVa !== '-')
            ? \App\Models\scctcust::showVA($nisForVa)
            : '';
    }

    $kelas = trim((string) ($siswa->DESC02 ?? ''));
    $kelompok = trim((string) ($siswa->DESC03 ?? ''));
    $kelasLabel = $kelas !== '' && $kelompok !== ''
        ? ($kelas . '-' . $kelompok)
        : trim($kelas . ' ' . $kelompok);
@endphp
@section('title', $pdfTitle ?? ($documentTitle . ' - ' . ($siswa->NMCUST ?? '')))
@section('content')
    <table width="100%" class="main-table" style="margin-bottom:12px;">
        <tr>
            <td class="info-label">No. VA</td>
            <td>: <strong>{{ $nova !== '' ? $nova : '-' }}</strong></td>
            <td class="info-label">Kelas</td>
            <td>: <strong>{{ $kelasLabel !== '' ? $kelasLabel : '-' }}</strong></td>
        </tr>
        <tr>
            <td class="info-label">Nama Siswa</td>
            <td>: <strong>{{ $siswa->NMCUST ?? '-' }}</strong></td>
            <td class="info-label">Jenjang</td>
            <td>: <strong>{{ $siswa->CODE02 ?? '-' }}</strong></td>
        </tr>
        <tr>
            <td class="info-label">Email</td>
            <td colspan="3">: <strong>{{ $siswa->EMAIL_ORTU ?? '-' }}</strong></td>
        </tr>
    </table>

    <table width="100%" class="table-border main-table">
        <thead style="background-color:#ededed;">
        <tr>
            <th style="width:7%;">No.</th>
            <th>Nama Tagihan</th>
            @if($showStatus)
                <th style="width:14%;">Status</th>
            @endif
            <th style="width:16%;">Tgl Expired</th>
            @if($isKartuLunas)
                <th style="width:16%;">Tanggal Bayar</th>
            @endif
            <th style="width:20%;" class="text-end">Nominal</th>
        </tr>
        </thead>
        <tbody>
        @forelse($kartuRows as $row)
            <tr>
                <td align="center">{{ $loop->iteration }}</td>
                <td>{{ $row['nama'] }}</td>
                @if($showStatus)
                    <td align="center">{{ !empty($row['lunas']) ? 'Lunas' : 'Belum Lunas' }}</td>
                @endif
                <td align="center">{{ ($row['expdate'] ?? '-') !== '-' ? $row['expdate'] : '-' }}</td>
                @if($isKartuLunas)
                    <td align="center">{{ ($row['paiddt'] ?? '-') !== '-' ? $row['paiddt'] : '-' }}</td>
                @endif
                <td align="right">@rupiah($row['total'])</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $colCount }}" align="center">Tidak ada data tagihan</td>
            </tr>
        @endforelse
        <tr>
            <td colspan="{{ $colCount - 1 }}" align="right"><strong>Total Tagihan</strong></td>
            <td align="right"><strong>@rupiah($sumTotal)</strong></td>
        </tr>
        <tr>
            <td colspan="{{ $colCount - 1 }}" align="right"><strong>Tagihan Terbayar</strong></td>
            <td align="right"><strong>@if($sumPaid > 0)@rupiah($sumPaid)@else Rp -@endif</strong></td>
        </tr>
        <tr>
            <td colspan="{{ $colCount - 1 }}" align="right"><strong>Sisa Tagihan</strong></td>
            <td align="right"><strong>@rupiah($sumSisa)</strong></td>
        </tr>
        </tbody>
    </table>
@endsection
