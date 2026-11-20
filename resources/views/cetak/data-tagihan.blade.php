@php
    use Carbon\Carbon;
@endphp
    <!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekap - Data Tagihan</title>

    <style type="text/css">
        * {
            font-family: Verdana, Arial, sans-serif;
        }

        table {
            font-size: x-small;
        }

        tfoot tr td {
            font-weight: bold;
            font-size: x-small;
        }

        .gray {
            background-color: lightgray
        }

        .table-border {
            border-collapse: collapse;
        }

        .table-border td, th {
            border: 2px solid lightgray;
            padding: 8px;
        }

        .table-border-top {
            border-collapse: collapse;
            border-bottom: 2px solid lightgray;
            padding: 8px;
        }

        .border-top-0 {
            border-top: 0 !important
        }

        .border-bottom-0 {
            border-bottom: 0 !important
        }

        .border-left-0 {
            border-left: 0 !important
        }

        .border-right-0 {
            border-right: 0 !important
        }

        .table-border-bottom {
            border-collapse: collapse;
            border-bottom: 2px solid lightgray;
            padding: 8px;
        }

        .border-bottom {
            border-collapse: collapse;
            border-bottom: 2px solid #000;
            text-align: left;
            padding: 8px;
        }

        h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 {
            margin-top: 0;
            margin-bottom: 0;
        }

        .text-start {
            text-align: left !important;
        }

        .text-end {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .align-start {
            align-items: start !important;
        }

        .align-end {
            align-items: end !important;
        }

        .align-center {
            align-items: center !important;
        }

        .w-100 {
            width: 100%;
        }

        .d-flex {
            display: flex !important;
        }

        .justify-content-start {
            justify-content: flex-start !important;
        }

        .justify-content-end {
            justify-content: flex-end !important;
        }

        .justify-content-center {
            justify-content: center !important;
        }

        .justify-content-between {
            justify-content: space-between !important;
        }

        .justify-content-around {
            justify-content: space-around !important;
        }

        .justify-content-evenly {
            justify-content: space-evenly !important;
        }

        .align-items-start {
            align-items: flex-start !important;
        }

        .align-items-end {
            align-items: flex-end !important;
        }

        .align-items-center {
            align-items: center !important;
        }

        .align-items-baseline {
            align-items: baseline !important;
        }

        .align-items-stretch {
            align-items: stretch !important;
        }

        .double-hr-alt {
            margin: 10px 0;
        }

        .line {
            width: 100%;
            height: 2px;
            background: black;
        }

        .line.bold {
            height: 4px; /* Bolder line */
            margin-bottom: 2px;
        }

        .line.thin {
            height: 1px; /* Thinner line */
            background: gray;
        }

        .breakable {
            display: inline-block;
            max-width: 70ch; /* Limit to 20 characters */
            overflow-wrap: break-word; /* Break long words */
            word-break: break-word; /* Ensure text wraps nicely */
            white-space: normal;
        }
    </style>
</head>
<body>
<table width="100%">
    <tr class="border-bottom">
        <td valign="top"></td>
        <td>
            <table width="100%">
                <tr>
                    <td rowspan="4" width="20%"><img src="{{public_path(config('app.logo'))}}" style="max-height: 180px; max-width: 100%" alt="image"/></td>
                    <td  class="text-center breakable" width="80%">
                        <h1 style="margin-left: -7% ;">{{config('app.nama_instansi')}}</h1>
                    </td>
                </tr>
                <tr>
                    <td  class="text-center breakable" width="80%">
                        <p style="margin-left: -7%; margin-top: 0;margin-bottom: 0;">{{ stripos((string) config('app.alamat'), 'semarang') !== false ? 'Kota Sidoarjo, Prov. Jawa Timur' : config('app.alamat') }}</p>
                    </td>
                </tr>
                <tr>
                    <td  class="text-center breakable" width="80%">
                        <p style="margin-left: -7%;margin-top: 0;margin-bottom: 0;">Email: {{config('app.email')}}</p>
                    </td>
                </tr>
                <tr>
                    <td  class="text-center breakable" width="80%">
                        <p style="margin-left: -7%;margin-top: 0;margin-bottom: 0;">No Telp. {{config('app.telepon')}}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
<div class="double-hr-alt">
    <div class="line bold"></div>
    <div class="line thin"></div>
</div>
<br>
<table width="100%">
    <tr>
        <td colspan="2" align="center"><h2>REKAP DATA TAGIHAN</h2></td>
    </tr>
    <tr>
        <td colspan="2" align="center"></td>
    </tr>
</table>

<br/>

@php
    $grandTagihan = 0;
    $grandTerbayar = 0;
    $grandSisa = 0;
    $kota = $domisili ?? config('app.domisili') ?: 'Sidoarjo';
    if (stripos((string) $kota, 'semarang') !== false) {
        $kota = 'Sidoarjo';
    }
@endphp
@foreach($posts as $post)
    @if(count($post['tagihans']) > 0)
        @php
            $sumTagihan = 0;
            $sumTerbayar = 0;
            $sumSisa = 0;
        @endphp

        <h3>{{$post->kode}} - {{$post->tagihan}}</h3>
        <table width="100%" class="table-border">
            <thead class="table-border" style="background-color: #ededed;">
            <tr>
                <th>NIS</th>
                <th>Nama</th>
                <th>Nama Tagihan</th>
                <th>Tahun Akademik</th>
                <th>Tanggal Bayar</th>
                <th>Tagihan</th>
                <th>Terbayar</th>
                <th>Sisa</th>
            </tr>
            </thead>
            <tbody>
            @php $BTASekarang = null; @endphp
            @foreach($post['tagihans'] as $item)
                <tr>
                    @php
                        $nis = $item['nocust'];
                        $nama = $item['nmcust'];
                        $nextNis = false;
                        $beforeNis = false;
                        $nisClass = null;
                        if (isset($post->tagihans[$loop->index + 1]) && $post->tagihans[$loop->index + 1]['nocust'] == $item['nocust']) {
                            $nextNis = true;
                        }
                        if (isset($post->tagihans[$loop->index - 1]) && $post->tagihans[$loop->index - 1]['nocust'] == $item['nocust']) {
                            $beforeNis = true;
                        }
                        $nisClass = !$nextNis ? '' : ' border-bottom-0';
                        $nisClass .= !$beforeNis ? '' : ' border-top-0';
                        if ($nextNis && $beforeNis) {
                            $nis = '';
                            $nama = '';
                        } elseif ($beforeNis) {
                            $nis = '';
                            $nama = '';
                        }

                        $paidDt = $item['PAIDDT'] ?? null;
                        $tanggalBayar = '-';
                        if ($paidDt) {
                            try {
                                $tanggalBayar = Carbon::parse($paidDt)->format('d-m-Y H:i');
                            } catch (\Throwable $e) {
                                $tanggalBayar = (string) $paidDt;
                            }
                        }

                        $billAm = (int) ($item['BILLAM'] ?? 0);
                        $billPaid = (int) ($item['BILLPAID'] ?? 0);
                        $paymentLeft = array_key_exists('PAYMENTLEFT', $item) && $item['PAYMENTLEFT'] !== null && $item['PAYMENTLEFT'] !== ''
                            ? (int) $item['PAYMENTLEFT']
                            : max(0, $billAm - $billPaid);
                    @endphp
                    <td class="{{$nisClass}} border-right-0">{{$nis}} </td>
                    <td class="{{$nisClass}} border-left-0">{{$nama}}</td>
                    <td>{{$item['BILLNM']}}</td>
                    <td>{{$item['BTA']}}</td>
                    <td>{{$tanggalBayar}}</td>
                    <td class="text-end">@rupiah($billAm)</td>
                    <td class="text-end">@rupiah($billPaid)</td>
                    <td class="text-end">@rupiah($paymentLeft)</td>
                </tr>
                @php
                    $sumTagihan += $billAm;
                    $sumTerbayar += $billPaid;
                    $sumSisa += $paymentLeft;
                @endphp
            @endforeach
            </tbody>
            <tfoot style="background-color: #ededed; font-weight: bold;">
            <tr>
                <td colspan="5" class="text-end">Total {{ $post->tagihan }}</td>
                <td class="text-end">@rupiah($sumTagihan)</td>
                <td class="text-end">@rupiah($sumTerbayar)</td>
                <td class="text-end">@rupiah($sumSisa)</td>
            </tr>
            </tfoot>
        </table>
        <br>
        @php
            $grandTagihan += $sumTagihan;
            $grandTerbayar += $sumTerbayar;
            $grandSisa += $sumSisa;
        @endphp
    @endif
@endforeach
<table width="100%" class="table-border">
    <tr style="background-color: #ededed; font-weight: bold;">
        <td>Total Keseluruhan</td>
        <td class="text-end">Tagihan: @rupiah($grandTagihan)</td>
        <td class="text-end">Terbayar: @rupiah($grandTerbayar)</td>
        <td class="text-end">Sisa: @rupiah($grandSisa)</td>
    </tr>
</table>
<br>
<br>
<table style="width: 100%">
    <tr>
        <td></td>
        <td align="right">{{ $kota }}, {{ Carbon::now()->locale('id')->isoFormat('dddd D MMMM YYYY') }}</td>
    </tr>
    <tr>
        <td></td>
        <td align="right" style="height: 70px;"></td>
    </tr>
    <tr>
        <td></td>
        <td align="right">Bagian Keuangan</td>
    </tr>
</table>

</body>
</html>
