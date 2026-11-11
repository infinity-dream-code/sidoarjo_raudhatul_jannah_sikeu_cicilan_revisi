@extends('layouts.export.kop_file')
@php use Carbon\Carbon; @endphp
@section('title', $pdfTitle ?? ('Kartu Tagihan Siswa - ' . ($siswa->NMCUST ?? '')))
@section('content')
    <table width="100%">
        <tr>
            <td colspan="2" align="center">
                <h4>KARTU TAGIHAN SISWA</h4>
                <div style="font-size:12px;color:#666;">(Belum Bayar)</div>
            </td>
        </tr>
    </table>
    @php
        $nisForVa = (!empty($siswa->NOCUST) && $siswa->NOCUST !== '-' && is_numeric($siswa->NOCUST))
            ? $siswa->NOCUST
            : ($siswa->NUM2ND ?? '');
        if (!isset($nova) || $nova === '' || $nova === null) {
            $nova = ($nisForVa && $nisForVa !== '-')
                ? \App\Models\scctcust::showVA($nisForVa)
                : '';
        }
    @endphp
    <table width="100%" class="main-table">
        <tr>
            <td style="width: auto" class="border-right-0">Nama Siswa</td>
            <td class="border-left-0">:<strong> {{$siswa->NMCUST??''}} </strong></td>
            <td style="width: auto" class="border-right-0">Kelas</td>
            <td class="border-left-0">:<strong> {{$siswa->DESC02??''}} - {{$siswa->DESC03??''}}</strong></td>
        </tr>
        <tr>
            <td style="width: auto" class="border-right-0">No. VA</td>
            <td class="border-left-0">:<strong> {{$nova ?? ''}}</strong></td>
            <td style="width: auto" class="border-right-0">Unit</td>
            <td class="border-left-0">:<strong> {{$siswa->CODE02??''}}</strong></td>
        </tr>
        <tr>
            <td class="border-right-0">Angkatan</td>
            <td class="border-left-0">:<strong> {{$siswa->DESC04??''}}</strong></td>
            <td class="border-right-0"></td>
            <td class="border-left-0"></td>
        </tr>
    </table>
    <table width="100%" class="table-border main-table">
        <thead class="table-border" style="background-color: #ededed;">
        <tr>
            <th>#</th>
            <th>Periode</th>
            <th>Nama Tagihan</th>
            <th>Jumlah</th>
            <th>Terbayar</th>
            <th>Sisa</th>
            <th>Expired</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        @php
            $kartuRows = \App\Support\KartuSiswaRows::build($tagihans);
            $sumTotal = array_sum(array_column($kartuRows, 'total'));
            $sumPaid = array_sum(array_column($kartuRows, 'terbayar'));
            $sumSisa = array_sum(array_column($kartuRows, 'sisa'));
        @endphp
        @forelse($kartuRows as $row)
            <tr>
                <th scope="row">{{$loop->index + 1}}</th>
                <td>{{$row['periode'] !== '' ? $row['periode'] : $row['bta']}}</td>
                <td>{{$row['nama']}}</td>
                <td align="right">@rupiah($row['total'])</td>
                <td align="right">@rupiah($row['terbayar'])</td>
                <td align="right">@rupiah($row['sisa'])</td>
                <td align="center">{{$row['expdate']}}</td>
                <td align="center"><span style="color:red;">BELUM LUNAS</span></td>
            </tr>
        @empty
            <tr>
                <td colspan="8" align="center">Tidak ada tagihan belum bayar</td>
            </tr>
        @endforelse
        </tbody>
        <tfoot style="background-color: #ededed; font-weight: bold;">
        <tr>
            <td colspan="3">Total</td>
            <td align="right">@rupiah($sumTotal)</td>
            <td align="right">@rupiah($sumPaid)</td>
            <td align="right">@rupiah($sumSisa)</td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td colspan="3">Total Tagihan</td>
            <td colspan="4" align="right">@rupiah($sumTotal)</td>
            <td rowspan="3"></td>
        </tr>
        <tr>
            <td colspan="3">Total Tagihan Terbayar</td>
            <td colspan="4" align="right">@rupiah($sumPaid)</td>
        </tr>
        <tr>
            <td colspan="3">Total Sisa Tagihan</td>
            <td colspan="4" align="right">@rupiah($sumSisa)</td>
        </tr>
        </tfoot>
    </table>
@endsection
