@extends('layouts.export.kop_file')
@php
    use Carbon\Carbon;
    $kartuMode = \App\Support\KartuSiswaRows::normalizeMode($kartuMode ?? 'all');
    // unpaid = Data Tagihan, paid = Data Tagihan Lunas, all = campur (Data Pembayaran/rekap)
    $documentTitle = match ($kartuMode) {
        'unpaid' => 'DATA TAGIHAN SISWA',
        'paid' => 'KARTU BAYAR',
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
    $statusLabel = $sumSisa <= 0 ? 'Lunas' : 'Belum Lunas';

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

    $dueLabel = '-';
    foreach ($kartuRows as $row) {
        if (($row['expdate'] ?? '-') !== '-') {
            try {
                $dueLabel = Carbon::createFromFormat('d-m-Y', $row['expdate'])->translatedFormat('d F Y');
            } catch (\Throwable) {
                $dueLabel = $row['expdate'];
            }
            break;
        }
    }
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
            <td>: <strong>{{ $siswa->EMAIL_ORTU ?? '-' }}</strong></td>
            @if($isKartuSiswa)
                <td class="info-label">Status</td>
                <td>: <strong>{{ $statusLabel }}</strong></td>
            @else
                <td class="info-label">Tanggal Jatuh Tempo</td>
                <td>: <strong>{{ $dueLabel }}</strong></td>
            @endif
        </tr>
    </table>

    <table width="100%" class="table-border main-table">
        <thead style="background-color:#ededed;">
        <tr>
            <th style="width:8%;">No.</th>
            <th>Nama Tagihan</th>
            @if($isKartuSiswa)
                <th style="width:16%;">Status</th>
            @endif
            <th style="width:28%;" class="text-end">Nominal</th>
        </tr>
        </thead>
        <tbody>
        @forelse($kartuRows as $row)
            <tr>
                <td align="center">{{ $loop->iteration }}</td>
                <td>{{ $row['nama'] }}</td>
                @if($isKartuSiswa)
                    <td align="center">{{ !empty($row['lunas']) ? 'Lunas' : 'Belum Lunas' }}</td>
                @endif
                <td align="right">@rupiah($row['total'])</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $isKartuSiswa ? 4 : 3 }}" align="center">Tidak ada data tagihan</td>
            </tr>
        @endforelse
        <tr>
            <td colspan="{{ $isKartuSiswa ? 3 : 2 }}" align="right"><strong>Total Tagihan</strong></td>
            <td align="right"><strong>@rupiah($sumTotal)</strong></td>
        </tr>
        <tr>
            <td colspan="{{ $isKartuSiswa ? 3 : 2 }}" align="right"><strong>Tagihan Terbayar</strong></td>
            <td align="right"><strong>@if($sumPaid > 0)@rupiah($sumPaid)@else Rp -@endif</strong></td>
        </tr>
        <tr>
            <td colspan="{{ $isKartuSiswa ? 3 : 2 }}" align="right"><strong>Sisa Tagihan</strong></td>
            <td align="right"><strong>@rupiah($sumSisa)</strong></td>
        </tr>
        </tbody>
    </table>
@endsection
