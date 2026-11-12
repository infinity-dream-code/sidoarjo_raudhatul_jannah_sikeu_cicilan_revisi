@extends('layouts.admin_new')
@section('title',$dataTitle??$mainTitle??$title??'')
@section('style')
    <link rel="stylesheet" href="{{asset('main/libs/select2/select2.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-bs5/datatables.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-responsive-bs5/responsive.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/bootstrap-daterangepicker/bootstrap-daterangepicker.css')}}">
    <style>
        #main_table_wrapper .dt-buttons {
            display: none !important;
        }

        .select2-container--default .select2-results__option[aria-disabled=true] {
            display: none;
        }
    </style>
@endsection
@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">
        @if(isset($dataTitle) && isset($mainTitle) && $mainTitle != $dataTitle)
            {{$mainTitle .' - '.$dataTitle}}
        @else
            {{$mainTitle??$title??''}}
        @endif
    </h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item">
            <a href="{{route('admin.index')}}" class="text-hover-primary">Beranda</a>
        </li>
        @if(isset($title))
            <li class="breadcrumb-item">
                {{$title}}
            </li>
        @endif
        @if(isset($mainTitle))
            <li class="breadcrumb-item">
                {{$mainTitle}}
            </li>
        @endif
        @if(isset($dataTitle) && isset($mainTitle) && $mainTitle != $dataTitle)
            <li class="breadcrumb-item active">
                {{$dataTitle}}
            </li>
        @endif
    </ul>

    <div class="card">
        <div class="card-header">
            <div class="row mb-3">
                <h5 class="mb-0 me-2">{{($dataTitle??$mainTitle??$title)}}</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row px-5 mb-2">
                <ul class="list-group list-group-timeline">
                    <li class="list-group-item list-group-timeline-danger">
                        <strong>Pastikan telah mengisi Tanggal Transaksi!</strong>
                    </li>
                    <li class="list-group-item list-group-timeline-danger">
                        <strong>Pastikan browser anda tidak memblokir <i>POP-UP</i>!</strong>
                    </li>
                </ul>
            </div>
            <form id="rekapForm">
                <fieldset class="form-fieldset">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-5">
                                <label class="form-label" for="dari-tanggal">Tanggal Transaksi <span
                                        class="text-warning">*</span>(tanggal-bulan-tahun - tanggal-bulan-tahun)</label>
                                <input type="text" id="tanggal-transaksi" name="filter[tanggal-transaksi]"
                                       placeholder="tanggal/bulan/tahun"
                                       class="form-control" autocomplete="false" inputmode="numeric"/>
                            </div>
                            <div class="mb-5 d-none">
                                <label class="form-label" for="tahun_akademik">
                                    Tahun Ajaran
                                </label>
                                <select class="form-select" id="tahun_akademik"
                                        name="filter[tahun_akademik]"
                                        data-control="select2"
                                        data-placeholder="Pilih Tahun Ajaran">
                                    <option value="all">Semua</option>
                                    @isset($thn_aka)
                                        @foreach($thn_aka as $item)
                                            <option
                                                value="{{$item->thn_aka}}">{{$item->thn_aka}}</option>
                                        @endforeach
                                    @else
                                        <option>data kosong</option>
                                    @endisset
                                </select>
                            </div>
                            <div class="mb-5 d-none">
                                <label class="form-label" for="post">
                                    Post
                                </label>
                                <select class="form-select" id="post"
                                        name="filter[post][]"
                                        data-control="select2"
                                        data-placeholder="Pilih Tagihan"
                                        multiple="multiple">
                                    <option value="all">Semua</option>
                                    @isset($post)
                                        @foreach($post as $item)
                                            <option
                                                value="{{$item->KodeAkun}}">{{$item->KodeAkun}} - {{$item->NamaAkun}}</option>
                                        @endforeach
                                    @else
                                        <option>data kosong</option>
                                    @endisset
                                </select>
                            </div>
                            <div class="mb-5">
                                <label class="form-label" for="filter[bank]">
                                    Bank
                                </label>
                                <select class="form-select" id="filter[bank]" name="filter[bank]"
                                        data-control="select2" data-placeholder="Pilih bank">
                                    <option value="all">Semua</option>
                                    @isset($bank)
                                        @foreach($bank as $key => $item)
                                            <option value="{{$key}}">{{$item}}</option>
                                        @endforeach
                                    @else
                                        <option>data kosong</option>
                                    @endisset
                                </select>
                            </div>
                            <div class="mb-5">
                                <label class="form-label" for="filter[nama_tagihan]">
                                    Nama Tagihan
                                </label>
                                <input type="text" class="form-control" id="filter[nama_tagihan]"
                                       name="filter[nama_tagihan]"
                                       placeholder="Ketik nama tagihan (sebagian kata boleh)">
                            </div>
                            <div class="mb-5">
                                <label class="form-label" for="filter_periode_mulai">
                                    Periode Mulai
                                </label>
                                <input type="month" class="form-control"
                                       placeholder="periode mulai" id="filter_periode_mulai"
                                       name="filter[periode_mulai]">
                            </div>
                            <div class="mb-5">
                                <label class="form-label" for="filter_periode_akhir">
                                    Periode Akhir
                                </label>
                                <input type="month" class="form-control"
                                       placeholder="periode akhir" id="filter_periode_akhir"
                                       name="filter[periode_akhir]">
                            </div>
                        </div>
                        <div class="col">
                            <div class="col mb-5 d-none">
                                <label class="form-label" for="filter[angkatan]]">
                                    Tahun Siswa
                                </label>
                                <select class="form-select" id="filter[angkatan]"
                                        name="filter[angkatan]"
                                        data-control="select2"
                                        data-placeholder="Pilih Angkatan Siswa">
                                    <option value="all">Semua</option>
                                    @isset($thn_aka)
                                        @foreach($thn_aka as $item)
                                            <option
                                                value="{{$item->thn_aka}}">{{$item->thn_aka}}</option>
                                        @endforeach
                                    @else
                                        <option>data kosong</option>
                                    @endisset
                                </select>
                            </div>
                            <div class="col mb-5">
                                <label class="form-label" for="filter[unit]">
                                    Sekolah / Unit
                                </label>
                                <input type="text" class="form-control" id="filter[unit]" name="filter[unit]"
                                       placeholder="Ketik sekolah/unit (sebagian kata boleh)">
                            </div>
                            <div class="col mb-5">
                                <label class="form-label" for="filter[kelas]">
                                    Kelas
                                </label>
                                <input type="text" class="form-control" id="filter[kelas]" name="filter[kelas]"
                                       placeholder="Ketik unit/kelas/kelompok (sebagian kata boleh)">
                            </div>
                            <div class="col mb-5">
                                <label class="form-label" for="filter[siswa]">
                                    NIS
                                </label>
                                <select class="form-select" id="filter[siswa]" name="filter[siswa]"
                                        data-control="select2-ajax-siswa"
                                        data-placeholder="Masukkan NIS / No. Pendaftaran / Nama Siswa">
                                    <option value=""></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="d-flex justify-content-center flex-column flex-md-row justify-content-md-end gap-4">
                            <button type="button" class="btn btn-facebook btn-print-rekap d-none" id="cetak-rekap">
                                <span class="ri-file-text-line me-2"></span>
                                Cetak Rekap
                            </button>
                            <button type="button" class="btn btn-facebook d-none" id="cetak-per-nis">
                                <span class="ri-user-line me-2"></span>
                                Cetak Per NIS
                            </button>
                            <button type="button" class="btn btn-facebook" id="cetak-kartu-siswa">
                                <span class="ri-profile-line me-2"></span>
                                Cetak Kartu Siswa
                            </button>
                            <button type="button" class="btn btn-facebook" id="cetak-kuitansi-pembayaran"
                                    title="Cetak kuitansi pembayaran (termasuk cicilan)">
                                <span class="ri-receipt-line me-2"></span>
                                Cetak Kuitansi Pembayaran
                            </button>
                            <button type="button" class="btn btn-success" id="export-excel">
                                <span class="ri-file-excel-2-line me-2"></span>
                                Export Excel
                            </button>
                            <button type="reset" class="btn btn-secondary" disabled>
                                <span class="ri-reset-left-line me-2"></span>
                                Reset
                            </button>
                            <button type="submit" class="btn btn-primary" disabled>
                                <span class="ri-search-line me-2"></span>
                                Cari
                            </button>
                        </div>
                    </div>
                </fieldset>
            </form>
            <div class="row px-5 mb-2">
                <ul class="list-group list-group-timeline">
                    <li class="list-group-item list-group-timeline-warning">
                        Untuk mencetak kartu siswa / kuitansi pembayaran, silahkan pilih data siswa terlebih dahulu!
                    </li>
                    <li class="list-group-item list-group-timeline-warning">
                        Cetak kartu siswa hanya per siswa. Kuitansi pembayaran bisa untuk cicilan maupun pelunasan (pilih baris transaksi yang sama siswa).
                    </li>
                </ul>
            </div>
        </div>
        <div class="card-datatable table-responsive text-nowrap">
            <table class="table table-sm table-bordered table-hover"
                   id="main_table">
                <thead class="table-light">

                </thead>
                <tbody>

                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{asset('main/libs/select2/select2.js')}}"></script>
    <script src="{{asset('main/libs/datatables-bs5/datatables-bootstrap5.js')}}"></script>
    <script src="{{asset('js/datatableCustom/Datatable-0-4.js')}}?v=20260916-pdf-va"></script>
    <script src="{{asset('main/libs/moment/moment.js')}}"></script>
    <script src="{{asset('main/libs/bootstrap-daterangepicker/bootstrap-daterangepicker.js')}}"></script>
    <script src="{{asset('js/unlimited-daterange.js')}}?v=20260911-no-limit"></script>

    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.12/pdfmake.min.js"
            integrity="sha512-qHAa7F8uOJ7X4H2YEaGgx9TQ4i0swpA7AC4b+V0RizIfzLfDIJdMdJnyvWYaG8utS9gmTn2PG4S7BPlCUXly1A=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.12/vfs_fonts.min.js"
            integrity="sha512-oD4QV6aKjCLkyWKPm5NhNwi/W+V8N4uTzK3KQt7igQ7WkECO34lN0tD3VRdDHom35PxyPVsAvg7fwXDwv5YNBw=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <script type="text/javascript" defer>
        const select2 = $(`[data-control='select2']`);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const headerLogo = "{{ base64_encode(file_get_contents(public_path(config('app.logo')))) }}";
        const muamalatLogo = @json(is_file(public_path('muamalat.png')) ? ('data:image/png;base64,' . base64_encode(file_get_contents(public_path('muamalat.png')))) : '');
        const tandaTangan = @json($tanda_tangan ?? null);
        const userName = "{{ Auth::user()->name ?? 'ADMIN' }}";
        const domisili = "{{ config('app.domisili') }}";
        const APP_VA_PREFIX = @json((string) (config('app.nova') ?: '797783'));
        const showVA = (nis) => typeof formatNoVA === 'function'
            ? formatNoVA(nis, APP_VA_PREFIX)
            : (() => {
                const digits = String(nis ?? '').replace(/\D/g, '');
                if (!digits) return '';
                return APP_VA_PREFIX + digits.padStart(16 - APP_VA_PREFIX.length, '0');
            })();

        if (typeof pdfMake !== 'undefined') {
            pdfMake.fonts = {
                Times: {
                    normal: 'Times-Roman',
                    bold: 'Times-Bold',
                    italics: 'Times-Italic',
                    bolditalics: 'Times-BoldItalic'
                },
                Roboto: {
                    normal: 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.66/fonts/Roboto/Roboto-Regular.ttf',
                    bold: 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.66/fonts/Roboto/Roboto-Medium.ttf',
                    italics: 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.66/fonts/Roboto/Roboto-Italic.ttf',
                    bolditalics: 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.66/fonts/Roboto/Roboto-MediumItalic.ttf'
                }
            };
        }

        let dtOptions = {
            tableId: 'main_table',
            formId: 'rekapForm',
            columnUrl: '{{($columnsUrl??null)}}',
            dataUrl: '{{($datasUrl??null)}}',
            dataColumns: [],
            thead: true,
            tfoot: true,
            paging: true,
            searching: true,
            fixedHeader: false,
            select: true,
            cache: true,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 75, 100],
            buttons: ['excel'],
            excelFilename: 'data pembayaran - export excel',
            excelCurrencyTotal: true,
        };

        document.addEventListener("DOMContentLoaded", function () {
            if (dtOptions.dataUrl && dtOptions.columnUrl) {
                getDT(dtOptions);
                if (dtOptions.formId) {
                    let filterForm = $(`#${dtOptions.formId}`);
                    filterForm.on('submit', function (e) {
                        e.preventDefault();
                        dataReFilter(dtOptions.tableId);
                    });
                    filterForm.on('reset', function (e) {
                        setTimeout(function () {
                            dataReFilter(dtOptions.tableId);
                            const select2InForm = select2.filter(`#${dtOptions.formId} [data-control='select2']`);
                            if (select2InForm.length) {
                                select2InForm.each(function () {
                                    let $this = $(this);
                                    $this.trigger('change');
                                });
                            }
                            $('[data-control="select2-ajax-siswa"]').val(null).trigger('change');
                        }, 0)
                    });
                }
            }

            $('#export-excel').on('click', function () {
                const table = $.fn.DataTable.isDataTable(`#${dtOptions.tableId}`)
                    ? $(`#${dtOptions.tableId}`).DataTable()
                    : null;
                if (!table) {
                    if (typeof warningAlert === 'function') {
                        warningAlert('Tabel belum siap. Silahkan tunggu sebentar lalu coba lagi.');
                    }
                    return;
                }

                let triggered = false;
                try {
                    const apiExcel = table.button('.buttons-excel');
                    if (apiExcel && apiExcel.length) {
                        apiExcel.trigger();
                        triggered = true;
                    }
                } catch (e) { /* ignore */ }

                if (!triggered) {
                    const excelBtn = document.querySelector(`#${dtOptions.tableId}_wrapper .buttons-excel`)
                        || document.querySelector('.dt-button-collection .buttons-excel');
                    if (excelBtn) {
                        excelBtn.click();
                        triggered = true;
                    }
                }

                if (!triggered) {
                    try {
                        const nested = table.button('0-0');
                        if (nested && nested.length) {
                            nested.trigger();
                            triggered = true;
                        }
                    } catch (e) { /* ignore */ }
                }

                if (!triggered && typeof warningAlert === 'function') {
                    warningAlert('Tombol export Excel tidak ditemukan. Silahkan muat ulang halaman.');
                }
            });

            if (select2.length) {
                select2.each(function () {
                    let $this = $(this);
                    // select2Focus($this);
                    $this.wrap('<div class="position-relative"></div>').select2({
                        placeholder: 'Select value',
                        dropdownParent: $this.parent()
                    });
                });
            }

            (function initSiswaSelect2Ajax() {
                const $siswaAjax = $('[data-control="select2-ajax-siswa"]');
                if (!$siswaAjax.length || typeof $.fn.select2 !== 'function') {
                    setTimeout(initSiswaSelect2Ajax, 200);
                    return;
                }
                if ($siswaAjax.hasClass('select2-hidden-accessible')) {
                    $siswaAjax.select2('destroy');
                }
                $siswaAjax.select2({
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $(document.body),
                    placeholder: $siswaAjax.data('placeholder') || 'Masukkan NIS / No. Pendaftaran / Nama Siswa',
                    ajax: {
                        url: '{{ route('admin.master-data.data-siswa.get-siswa-select2') }}',
                        dataType: 'json',
                        delay: 300,
                        data: function (params) {
                            return { term: params.term };
                        },
                        processResults: function (data) {
                            return { results: Array.isArray(data) ? data : [] };
                        },
                        cache: true
                    },
                    language: {
                        inputTooShort: function () {
                            return 'Masukkan NIS atau No. Pendaftaran atau Nama Siswa';
                        },
                        noResults: function () {
                            const term = $siswaAjax.data('select2')?.$dropdown?.find('.select2-search__field').val()
                                || $('.select2-container--open .select2-search__field').val()
                                || '';
                            const w = $.isNumeric(term) ? 'NIS' : 'Nama';
                            return 'Siswa dengan ' + w + ': <span class="bg-label-danger"><b>' + term + '</b></span> tidak ditemukan!';
                        },
                        searching: function () {
                            return 'Mencari Siswa ......';
                        }
                    },
                    escapeMarkup: function (markup) {
                        return markup;
                    },
                    minimumInputLength: 3,
                });
            })();

            bindUnlimitedDateRange('#tanggal-transaksi');
            const periodeMulai = $('#filter_periode_mulai');
            const periodeAkhir = $('#filter_periode_akhir');

            periodeMulai.on('change', function () {
                const val = $(this).val();
                if (val) {
                    periodeAkhir.attr('min', val);
                } else {
                    periodeAkhir.removeAttr('min');
                }
            });

            periodeAkhir.on('change', function () {
                const val = $(this).val();
                if (val) {
                    periodeMulai.attr('max', val);
                } else {
                    periodeMulai.removeAttr('max');
                }
            });

            function formatRupiah(amount) {
                const n = Number(amount) || 0;
                return 'Rp. ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            function terbilangRupiah(amount) {
                const satuan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
                const spell = (n) => {
                    n = Math.floor(Math.abs(Number(n) || 0));
                    if (n < 12) return satuan[n];
                    if (n < 20) return satuan[n - 10] + ' Belas';
                    if (n < 100) return (satuan[Math.floor(n / 10)] + ' Puluh ' + spell(n % 10)).trim();
                    if (n < 200) return ('Seratus ' + spell(n - 100)).trim();
                    if (n < 1000) return (satuan[Math.floor(n / 100)] + ' Ratus ' + spell(n % 100)).trim();
                    if (n < 2000) return ('Seribu ' + spell(n - 1000)).trim();
                    if (n < 1000000) return (spell(Math.floor(n / 1000)) + ' Ribu ' + spell(n % 1000)).trim();
                    if (n < 1000000000) return (spell(Math.floor(n / 1000000)) + ' Juta ' + spell(n % 1000000)).trim();
                    return (spell(Math.floor(n / 1000000000)) + ' Miliar ' + spell(n % 1000000000)).trim();
                };
                const n = Math.round(Math.abs(Number(amount) || 0));
                return (n === 0 ? 'Nol' : spell(n)) + ' Rupiah';
            }

            function formatKelasLabel(desc02, desc03) {
                const a = String(desc02 || '').trim();
                const b = String(desc03 || '').trim();
                if (a && b) return `${a}-${b}`;
                return (a + ' ' + b).trim() || '-';
            }

            function getContentWidth(pageSize, orientation, margins) {
                const sizes = {A4: [595.28, 841.89]};
                const size = sizes[pageSize] || sizes.A4;
                const width = orientation === 'landscape' ? size[1] : size[0];
                return width - (margins[0] || 0) - (margins[2] || 0);
            }

            async function generatePdf(title, bodyContent, unit_logo = false, fileTitle = null) {
                try {
                    let logo = 'data:image/jpeg;base64,' + headerLogo;
                    if (unit_logo && typeof getLogoUnit === 'function') {
                        logo = await getLogoUnit(unit_logo);
                    }

                    const orientation = 'portrait';
                    const pageMargins = [24, 24, 24, 24];
                    const tanggalSekarang = typeof formatDateId === 'function'
                        ? formatDateId(new Date())
                        : new Date().toLocaleDateString('id-ID', {
                            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
                        }).replace(/^([^,]+),\s+/, '$1 ');
                    const availableWidth = getContentWidth('A4', orientation, pageMargins);
                    const titleUpper = String(title || '').toUpperCase();
                    const isKuitansi = titleUpper.startsWith('KUITANSI');
                    const compactHeader = isKuitansi || ['KARTU SISWA'].includes(titleUpper);
                    const skipFooter = isKuitansi;

                    const centerCell = compactHeader
                        ? {text: titleUpper, fontSize: 18, bold: true, alignment: 'center', margin: [0, 18, 0, 18]}
                        : {text: titleUpper, style: 'title'};

                    const headerTable = {
                        table: {
                            widths: [70, '*', 70],
                            body: [[
                                logo ? {image: logo, fit: [64, 64], alignment: 'center', margin: [0, 2, 0, 2]} : {text: ''},
                                centerCell,
                                muamalatLogo ? {image: muamalatLogo, fit: [72, 48], alignment: 'center', margin: [0, 10, 0, 2]} : {text: ''}
                            ]]
                        },
                        layout: 'noBorders'
                    };

                    const footer = skipFooter ? null : {
                        columns: [
                            {text: '', width: '*'},
                            {
                                stack: [
                                    {text: `${domisili}, ${tanggalSekarang}`, margin: [0, 10, 0, 0], alignment: 'center'},
                                    tandaTangan ? {image: tandaTangan, width: 100, alignment: 'center'} : {},
                                    {text: compactHeader ? 'ADMIN' : (userName || 'ADMIN'), alignment: 'center'}
                                ],
                                width: 'auto'
                            }
                        ]
                    };

                    const content = [
                        headerTable,
                        {
                            margin: [0, 6, 0, 10],
                            canvas: [
                                {type: 'line', x1: 0, y1: 0, x2: availableWidth, y2: 0, lineWidth: 1.5},
                                {type: 'line', x1: 0, y1: 3, x2: availableWidth, y2: 3, lineWidth: 0.5, lineColor: '#888'}
                            ]
                        },
                        ...(!compactHeader ? [{text: titleUpper, style: 'title', margin: [0, 5, 0, 5]}] : []),
                        ...bodyContent,
                    ];
                    if (footer) content.push(footer);

                    const docTitle = String(fileTitle || title || 'Kuitansi Pembayaran');
                    pdfMake.createPdf({
                        info: {title: docTitle, subject: docTitle},
                        pageSize: 'A4',
                        pageOrientation: orientation,
                        pageMargins: pageMargins,
                        content: content,
                        styles: {
                            title: {fontSize: 14, bold: true, alignment: 'center'},
                            tableHeader: {bold: true, fillColor: '#ededed', alignment: 'center'},
                        },
                        defaultStyle: {font: 'Times'}
                    }).open();

                    successAlert('File telah didownload <br>' +
                        '<p><span class="badge badge-dot bg-danger me-1"></span> Cek pada menu unduhan browser anda untuk memeriksa!</p>');
                } catch (e) {
                    console.error('Error generating PDF:', e);
                    errorAlert(e.message || 'Gagal membuat PDF');
                }
            }

            async function generateKuitansiPembayaran() {
                const table = DT[`${dtOptions.tableId}`];
                if (!table) {
                    warningAlert('Tabel belum siap. Silahkan tunggu sebentar lalu coba lagi.');
                    return;
                }

                let data = table.rows({selected: true}).data().toArray();
                data = data.filter(item => {
                    const tanggal = item.PAIDDT ?? item.TRXDATE;
                    const nominal = Number(item.BILLAM ?? item.DEBET ?? 0);
                    return tanggal && nominal > 0;
                });

                if (!data[0]) {
                    warningAlert('Silahkan pilih data pembayaran terlebih dahulu!');
                    return;
                }

                const firstKey = String(data[0]?.CUSTID ?? data[0]?.custid ?? data[0]?.NOCUST ?? data[0]?.nocust ?? '');
                const allSame = data.every(row => String(row.CUSTID ?? row.custid ?? row.NOCUST ?? row.nocust ?? '') === firstKey);
                if (!allSame) {
                    warningAlert('Silahkan pilih data siswa yang sama!');
                    return;
                }

                loadingAlert('Membuat Kuitansi Pembayaran...');

                const siswa = data[0];
                const nocust = siswa.NOCUST || siswa.nocust;
                const hasNis = nocust && nocust !== '-';
                const noVa = hasNis ? showVA(nocust) : (siswa.NOVA || '-');
                const kelasLabel = siswa.kelas_label
                    ? String(siswa.kelas_label).trim().replace(/\s+/, '-')
                    : formatKelasLabel(siswa.DESC02, siswa.DESC03);
                const metodeLabel = siswa.METODE_BAYAR || siswa.METODE || '-';
                let paidRaw = data.map(i => i.PAIDDT || i.TRXDATE).find(Boolean);
                let paidLabel = '-';
                if (paidRaw && paidRaw !== '0000-00-00 00:00:00') {
                    paidLabel = typeof formatDateId === 'function'
                        ? formatDateId(paidRaw)
                        : new Date(paidRaw).toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'});
                }

                const infoRows = [
                    [
                        {text: 'Nama Siswa', border: [false, false, false, false]},
                        {text: ': ' + (siswa.NMCUST || siswa.nmcust || '-'), border: [false, false, false, false]},
                        {text: 'No. VA', border: [false, false, false, false]},
                        {text: ': ' + noVa, border: [false, false, false, false]},
                    ],
                    [
                        {text: 'Kelas', border: [false, false, false, false]},
                        {text: ': ' + kelasLabel, border: [false, false, false, false]},
                        {text: 'Metode Pembayaran', border: [false, false, false, false]},
                        {text: ': ' + metodeLabel, border: [false, false, false, false]},
                    ],
                    [
                        {text: 'Jenjang', border: [false, false, false, false]},
                        {text: ': ' + (siswa.CODE02 || '-'), border: [false, false, false, false]},
                        {text: 'Tanggal Pembayaran', border: [false, false, false, false]},
                        {text: ': ' + paidLabel, border: [false, false, false, false]},
                    ],
                ];

                const content = [{
                    table: {widths: ['18%', '32%', '22%', '28%'], body: infoRows},
                    layout: 'noBorders',
                    margin: [0, 0, 0, 10],
                    fontSize: 10,
                }];

                const tableBody = [
                    ['No.', 'Uraian', 'Nominal'].map(h => ({text: h, style: 'tableHeader'})),
                ];
                let totalBayar = 0;
                data.forEach((item, index) => {
                    const billPaid = Number(item.BILLAM ?? item.DEBET ?? 0);
                    totalBayar += billPaid;
                    const installment = Number(item.INSTALLMENT ?? 0);
                    const uraian = installment > 0
                        ? ((item.BILLNM || '-') + ' (Cicilan)')
                        : (item.BILLNM || '-');
                    tableBody.push([
                        {text: String(index + 1), alignment: 'center'},
                        {text: uraian, alignment: 'left'},
                        {text: formatRupiah(billPaid), alignment: 'right'},
                    ]);
                });

                content.push({
                    table: {widths: ['10%', '*', '28%'], body: tableBody},
                    layout: {
                        fillColor: (rowIndex) => rowIndex === 0 ? '#ededed' : null,
                        hLineWidth: () => 0.6,
                        vLineWidth: () => 0.6,
                    },
                    margin: [0, 0, 0, 10],
                    fontSize: 11,
                });

                content.push({
                    columns: [
                        {text: '', width: '*'},
                        {
                            width: 'auto',
                            columns: [
                                {text: 'Total Pembayaran', bold: true, width: 'auto', margin: [0, 0, 12, 0]},
                                {text: formatRupiah(totalBayar), bold: true, decoration: 'underline', width: 'auto'},
                            ]
                        }
                    ],
                    margin: [0, 4, 0, 16],
                });

                content.push({
                    columns: [
                        {
                            width: '*',
                            stack: [
                                {text: 'Terbilang :', margin: [0, 0, 0, 4]},
                                {
                                    table: {
                                        widths: ['*'],
                                        body: [[{
                                            text: terbilangRupiah(totalBayar),
                                            italics: true,
                                            bold: true,
                                            margin: [8, 6, 8, 6],
                                        }]]
                                    },
                                    layout: {
                                        hLineWidth: () => 1,
                                        vLineWidth: () => 1,
                                    }
                                }
                            ]
                        },
                        {
                            width: 140,
                            alignment: 'center',
                            stack: [
                                {text: 'Diterima Oleh,', margin: [0, 0, 0, 36]},
                                {text: 'ADMIN', bold: true},
                            ]
                        }
                    ]
                });

                Swal.close();
                await generatePdf('KUITANSI PEMBAYARAN', content, siswa.CODE02 || false, 'data pembayaran - kuitansi pembayaran');
            }

            const btnKuitansiPembayaran = document.getElementById('cetak-kuitansi-pembayaran');
            if (btnKuitansiPembayaran) {
                btnKuitansiPembayaran.addEventListener('click', async function (e) {
                    e.preventDefault();
                    await generateKuitansiPembayaran();
                });
            }

            document.getElementById('cetak-kartu-siswa').addEventListener('click', function (e) {
                e.preventDefault();
                let data = DT[`${dtOptions.tableId}`].rows({selected: true}).data();

                if (!data[0]) {
                    warningAlert('silahkan pilih siswa!')
                    return;
                }
                loadingAlert(`Membuat Kartu Siswa ... <br> Proses ini membutuhkan waktu beberapa saat<br><hr>
                    <p><span class="badge badge-dot bg-danger me-1"></span> Pastikan browser anda tidak memblokir <i>POP-UP</i>! </p>
                `);
                let url = '{{route('admin.keuangan.penerimaan-siswa.rekap-penerimaan.cetak-kartu-siswa')}}';
                const form = new FormData(document.getElementById('rekapForm'));
                const params = new URLSearchParams();
                for (const [key, value] of form.entries()) {
                    params.append(key, value);
                }
                params.append('custid', data[0].CUSTID ?? data[0].custid ?? '')
                const fullUrl = `${url}?${params.toString()}`;
                const request = new Request(
                    fullUrl, {
                        method: "GET",
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/pdf'
                        }
                    });
                const pdfTabTitle = 'data pembayaran - kartu siswa';

                fetch(request)
                    .then(async res => {
                        const blob = await res.blob();
                        if (!res.ok || (blob.type && blob.type.indexOf('pdf') === -1 && blob.type.indexOf('octet-stream') === -1)) {
                            let message = 'Tagihan Tidak Ditemukan';
                            try {
                                const text = await blob.text();
                                const json = JSON.parse(text);
                                message = json.message || json.error || message;
                            } catch (err) { /* ignore */ }
                            const error = new Error(message);
                            error.status = res.status;
                            throw error;
                        }
                        return blob;
                    })
                    .then(blob => {
                        const fileUrl = URL.createObjectURL(blob);
                        const tab = window.open('', '_blank');
                        if (!tab) {
                            window.open(fileUrl, '_blank');
                        } else {
                            tab.document.write(
                                '<!DOCTYPE html><html><head><title>' + pdfTabTitle + '</title></head>' +
                                '<body style="margin:0">' +
                                '<embed src="' + fileUrl + '" type="application/pdf" style="border:0;width:100%;height:100vh">' +
                                '</body></html>'
                            );
                            tab.document.close();
                        }
                        successAlert('Sukses, Rekap terbuka pada tab baru');
                    })
                    .catch(error => {
                        if (error.status === 422) {
                            const errors = error.error || error.errors;
                            errorAlert(error.message);
                            if (errors) {
                                processErrors(errors)
                            }
                        } else {
                            const errorMessages = {
                                401: 'Permintaan gagal diproses. Silakan coba lagi.',
                                403: 'Anda tidak memiliki izin untuk mengakses halaman ini 😖',
                                404: 'Halaman yang dituju tidak ditemukan 🧐',
                                405: 'Metode tidak valid 🧐 <br>silahkan muat ulang halaman dan coba lagi!',
                                419: 'Permintaan gagal diproses. Silakan coba lagi.',
                                429: 'Terlalu banyak permintaan akses <br>silahkan tunggu beberapa saat 🙏',
                            };
                            errorAlert(errorMessages[error.status] || "Terjadi kesalahan, silahkan coba memuat ulang halaman");
                        }
                    });
            })

            document.getElementById('cetak-per-nis').addEventListener('click', function (e) {
                e.preventDefault();
                loadingAlert(`Membuat Rekap Per NIS ... <br> Proses ini membutuhkan waktu beberapa saat<br><hr>
                    <p><span class="badge badge-dot bg-danger me-1"></span> Pastikan browser anda tidak memblokir <i>POP-UP</i>! </p>
                `);
                let url = '{{route('admin.keuangan.penerimaan-siswa.rekap-penerimaan.cetak-per-nis')}}';
                const form = new FormData(document.getElementById('rekapForm'));
                const params = new URLSearchParams();
                for (const [key, value] of form.entries()) {
                    params.append(key, value);
                }
                const fullUrl = `${url}?${params.toString()}`;
                const request = new Request(
                    fullUrl, {
                        method: "GET",
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/pdf'
                        }
                    });

                fetch(request)
                    .then(res => res.blob())
                    .then(blob => {
                        const url = URL.createObjectURL(blob);
                        window.open(url, '_blank');
                        successAlert('Sukses, Rekap Per NIS terbuka pada tab baru');
                    })
                    .catch(error => {
                        if (error.status === 422) {
                            const errors = error.error || error.errors;
                            errorAlert(error.message);
                            if (errors) {
                                processErrors(errors)
                            }
                        } else {
                            const errorMessages = {
                                401: 'Permintaan gagal diproses. Silakan coba lagi.',
                                403: 'Anda tidak memiliki izin untuk mengakses halaman ini 😖',
                                404: 'Halaman yang dituju tidak ditemukan 🧐',
                                405: 'Metode tidak valid 🧐 <br>silahkan muat ulang halaman dan coba lagi!',
                                419: 'Permintaan gagal diproses. Silakan coba lagi.',
                                429: 'Terlalu banyak permintaan akses <br>silahkan tunggu beberapa saat 🙏',
                            };
                            errorAlert(errorMessages[error.status] || "Terjadi kesalahan, silahkan coba memuat ulang halaman");
                        }
                    });
            })

            document.getElementById('cetak-rekap').addEventListener('click', async function (e) {
                e.preventDefault();
                const form = new FormData(document.getElementById('rekapForm'));

                const params = new URLSearchParams();
                for (const [key, value] of form.entries()) {
                    params.append(key, value);
                }

                const unitValue = params.get('filter[unit]');
                const kelasValue = params.get('filter[kelas]');
                const invalidValues = [null, '', 'undefined', 'all'];

                loadingAlert(`Membuat Rekap ... <br> Proses ini membutuhkan waktu beberapa saat<br><hr>
                    <p><span class="badge badge-dot bg-danger me-1"></span> Pastikan browser anda tidak memblokir <i>POP-UP</i>! </p>
                `);
                let url = '{{route('admin.keuangan.penerimaan-siswa.rekap-penerimaan.cetak-rekap')}}';
                const fullUrl = `${url}?${params.toString()}`;
                const request = new Request(
                    fullUrl, {
                        method: "GET",
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': "application/json"
                        }
                    });

                try {
                    const response = await fetch(request);

                    if (!response.ok) {
                        throw await buildHttpError(response);
                    }

                    const result = await response.json();

                    if (!result?.tagihans?.length) {
                        throw createError("Data Tagihan Kosong", 422);
                    }

                    let matrix = generateTableRekap(result.tagihans)
                    let file = await exportExcel(matrix, params)

                    if (file) {
                        successAlert('Sukses, Rekap telah dicetak');
                    }
                } catch (error) {
                    if (error.status === 422) {
                        const errors = error.error || error.errors;
                        errorAlert(error.message);
                        if (errors) {
                            processErrors(errors)
                        }
                    } else {
                        const errorMessages = {
                            401: 'Permintaan gagal diproses. Silakan coba lagi.',
                            403: 'Anda tidak memiliki izin untuk mengakses halaman ini 😖',
                            404: 'Halaman yang dituju tidak ditemukan 🧐',
                            405: 'Metode tidak valid 🧐 <br>silahkan muat ulang halaman dan coba lagi!',
                            419: 'Permintaan gagal diproses. Silakan coba lagi.',
                            429: 'Terlalu banyak permintaan akses <br>silahkan tunggu beberapa saat 🙏',
                        };
                        errorAlert(errorMessages[error.status] || "Terjadi kesalahan, silahkan coba memuat ulang halaman");
                    }
                }

            });

            async function getLogoUnit(unit = false) {
                const fallbackLogo = 'data:image/jpeg;base64,' + "{{ base64_encode(file_get_contents(public_path(config('app.logo')))) }}";
                try {
                    if (!unit) {
                        throw 'error';
                    }
                    const cacheKey = `logo_unit_${unit}`;
                    const cachedLogo = localStorage.getItem(cacheKey);
                    if (cachedLogo) {
                        return cachedLogo;
                    }
                    const params = new URLSearchParams();
                    params.append('unit', unit);
                    const request = new Request(
                        `{{ route('admin.master-data.get-logo') }}?${params.toString()}`,
                        {
                            method: "GET",
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            }
                        }
                    );
                    const response = await fetch(request);
                    if (!response.ok) {
                        throw 'error';
                    }
                    const result = await response.json();
                    if (!result.data) {
                        throw 'error';
                    }
                    localStorage.setItem(cacheKey, result.data);
                    return result.data;
                } catch {
                    return fallbackLogo;
                }
            }

            function parseDDMMYYYY(str) {
                if (!str) return null;

                const [dd, mm, yyyy] = str.split("-").map(Number);
                if (!dd || !mm || !yyyy) return null;

                return new Date(yyyy, mm - 1, dd, 12);
            }

            function generateTableRekap(data) {
                if (!Array.isArray(data) || data.length === 0) return null;

                const kelasOrder = [];
                const kelasSet = new Set();
                const wismaOrder = [];
                const wismaSet = new Set();

                data.forEach((row) => {
                    const kelasLabel = `${row.CODE02 ?? '-'} ${row.DESC03 ?? ''}`.trim();
                    const wisma = `${row.GetWisma ?? ''}`.trim() || 'Reguler';

                    if (!kelasSet.has(kelasLabel)) {
                        kelasSet.add(kelasLabel);
                        kelasOrder.push(kelasLabel);
                    }
                    if (!wismaSet.has(wisma)) {
                        wismaSet.add(wisma);
                        wismaOrder.push(wisma);
                    }
                });

                const rowMap = new Map();
                data.forEach((row) => {
                    const kelasLabel = `${row.CODE02 ?? '-'} ${row.DESC03 ?? ''}`.trim();
                    const wisma = `${row.GetWisma ?? ''}`.trim() || 'Reguler';
                    const tahun = row.BTA ?? '-';
                    const kode = row.KodePost ?? '-';
                    const nama = row.BILLNM ?? row.NamaAkun ?? '-';
                    const val = Number(row.BILLAM ?? 0);
                    if (!val) return;

                    const mapKey = `${tahun}||${kode}||${nama}`;
                    if (!rowMap.has(mapKey)) {
                        rowMap.set(mapKey, {
                            tahun,
                            kode,
                            nama,
                            byClass: {},
                            total: 0
                        });
                    }
                    const target = rowMap.get(mapKey);
                    if (!target.byClass[kelasLabel]) target.byClass[kelasLabel] = {};
                    target.byClass[kelasLabel][wisma] = (target.byClass[kelasLabel][wisma] ?? 0) + val;
                    target.total += val;
                });

                const filteredRows = Array.from(rowMap.values()).sort((a, b) => {
                    if (a.tahun !== b.tahun) return String(a.tahun).localeCompare(String(b.tahun));
                    return String(a.kode).localeCompare(String(b.kode));
                });
                return {
                    kelasOrder,
                    wismaOrder,
                    rows: filteredRows
                };
            }

            async function exportExcel(matrix, params) {
                if (!matrix || !matrix.rows || matrix.rows.length === 0) return;

                const invalidValues = [null, '', 'undefined', 'all'];
                let statusBayarVal = params.get('filter[status_bayar]') ?? null;
                if (invalidValues.includes(statusBayarVal)) {
                    statusBayarVal = false;
                }
                const kelasText = (params.get('filter[kelas]') || '').trim();
                let kelasVal = (!invalidValues.includes(kelasText) && kelasText !== '') ? kelasText : 'Semua';
                const unitText = (params.get('filter[unit]') || '').trim();
                if (!invalidValues.includes(unitText) && unitText !== '') {
                    kelasVal = unitText + (kelasVal !== 'Semua' ? ', ' + kelasVal : '');
                }

                let thnAkaVal = params.get('filter[tahun_akademik]') ?? null;
                if (invalidValues.includes(thnAkaVal)) {
                    thnAkaVal = 'Semua';
                }

                let tanggalTransaksi = params.get('filter[tanggal-transaksi]') ?? '';
                let tanggalSplit = tanggalTransaksi.includes(' - ')
                    ? tanggalTransaksi.split(' - ')
                    : [];

                const wbTitle = "REKAP PENERIMAAN"
                const wb = new ExcelJS.Workbook();
                const ws = wb.addWorksheet(wbTitle);
                const rows = matrix.rows;
                const kelasOrder = matrix.kelasOrder;
                const wismaOrder = matrix.wismaOrder;

                ws.insertRow(1, [wbTitle]);
                ws.insertRow(2, ["Unit, Kelas", kelasVal.replace(/~/g, " - ")]);
                ws.insertRow(3, ["Tahun Ajaran", thnAkaVal]);
                ws.insertRow(4, ["Dari", tanggalSplit[0] ? parseDDMMYYYY(tanggalSplit[0]) : '-']);
                ws.insertRow(5, ["Hingga", tanggalSplit[1] ? parseDDMMYYYY(tanggalSplit[1]) : '-']);

                [4, 5].forEach(rowNumber => {
                    const cell = ws.getRow(rowNumber).getCell(2);
                    if (cell.value instanceof Date) {
                        cell.value = typeof formatDateId === 'function'
                            ? formatDateId(cell.value)
                            : cell.value.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).replace(/^([^,]+),\s*/, '$1 ');
                    }
                    ws.getColumn(2).width = Math.max(ws.getColumn(2).width || 10, 30);
                });

                const boldRows = [1, 2, 3, 4, 5];

                boldRows.forEach(rowNumber => {
                    const row = ws.getRow(rowNumber);

                    row.eachCell({includeEmpty: true}, cell => {
                        cell.font = {bold: true};
                    });

                    row.commit();
                });

                ws.insertRow(7, []);

                const headerRow1Number = 8;
                const headerRow1 = ws.getRow(headerRow1Number);
                const headerRow2 = ws.getRow(headerRow1Number + 1);

                let col = 1;
                headerRow1.getCell(col).value = 'Thn Akademik'; ws.mergeCells(headerRow1Number, col, headerRow1Number + 1, col); col++;
                headerRow1.getCell(col).value = 'Kode'; ws.mergeCells(headerRow1Number, col, headerRow1Number + 1, col); col++;
                headerRow1.getCell(col).value = 'Nama'; ws.mergeCells(headerRow1Number, col, headerRow1Number + 1, col); col++;

                kelasOrder.forEach((kelas) => {
                    const startCol = col;
                    wismaOrder.forEach((w) => {
                        headerRow2.getCell(col).value = w;
                        col++;
                    });
                    headerRow2.getCell(col).value = 'Sum';
                    const endCol = col;
                    ws.mergeCells(headerRow1Number, startCol, headerRow1Number, endCol);
                    headerRow1.getCell(startCol).value = kelas;
                    col++;
                });

                headerRow1.getCell(col).value = 'Total';
                ws.mergeCells(headerRow1Number, col, headerRow1Number + 1, col);

                const lastCol = col;

                for (let i = 1; i <= lastCol; i++) {
                    ws.getColumn(i).width = i <= 3 ? [12, 10, 26][i - 1] : 14;
                    [headerRow1, headerRow2].forEach((r) => {
                        const cell = r.getCell(i);
                        cell.font = {bold: true};
                        cell.alignment = {horizontal: "center", vertical: "middle"};
                        cell.border = fullBorder();
                        cell.fill = cellBGColor();
                    });
                }

                const dataStartRow = headerRow1Number + 2;
                let currentRow = dataStartRow;

                rows.forEach((r, idx) => {
                    const row = ws.getRow(currentRow);
                    row.getCell(1).value = idx === 0 ? (r.tahun || thnAkaVal || '') : '';
                    row.getCell(2).value = r.kode;
                    row.getCell(3).value = r.nama;

                    let c = 4;
                    kelasOrder.forEach((kelas) => {
                        let subtotalKelas = 0;
                        wismaOrder.forEach((w) => {
                            const val = Number(r.byClass?.[kelas]?.[w] ?? 0);
                            subtotalKelas += val;
                            row.getCell(c).value = val;
                            row.getCell(c).numFmt = '#,##0';
                            row.getCell(c).alignment = {horizontal: 'right'};
                            c++;
                        });
                        row.getCell(c).value = subtotalKelas;
                        row.getCell(c).numFmt = '#,##0';
                        row.getCell(c).alignment = {horizontal: 'right'};
                        c++;
                    });
                    row.getCell(c).value = Number(r.total ?? 0);
                    row.getCell(c).numFmt = '#,##0';
                    row.getCell(c).alignment = {horizontal: 'right'};

                    for (let i = 1; i <= lastCol; i++) {
                        row.getCell(i).border = fullBorder();
                    }
                    currentRow++;
                });

                const totalRow = ws.getRow(currentRow);
                totalRow.getCell(1).value = '';
                totalRow.getCell(2).value = '';
                totalRow.getCell(3).value = 'Total';
                totalRow.getCell(3).font = {bold: true};

                for (let i = 4; i <= lastCol; i++) {
                    const colLetter = ws.getColumn(i).letter;
                    totalRow.getCell(i).value = {formula: `SUM(${colLetter}${dataStartRow}:${colLetter}${currentRow - 1})`};
                    totalRow.getCell(i).numFmt = '#,##0';
                    totalRow.getCell(i).font = {bold: true};
                    totalRow.getCell(i).alignment = {horizontal: 'right'};
                }
                for (let i = 1; i <= lastCol; i++) {
                    totalRow.getCell(i).border = fullBorder();
                    totalRow.getCell(i).fill = cellBGColor();
                }

                const buffer = await wb.xlsx.writeBuffer();
                const blob = new Blob([buffer], {
                    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                });

                const a = document.createElement("a");
                a.href = URL.createObjectURL(blob);
                a.download = wbTitle + " - " + kelasVal.replace(/~/g, " - ") + ".xlsx";
                a.click();

                return true;
            }

            function fullBorder() {
                return {
                    top: {style: "thin"},
                    left: {style: "thin"},
                    bottom: {style: "thin"},
                    right: {style: "thin"}
                };
            }

            function cellBGColor(){
                return {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: 'FFEBE1FF' }
                }
            }

            function createError(message, status, extra = {}) {
                const err = new Error(message);
                err.status = status;
                Object.assign(err, extra);
                return err;
            }

            async function buildHttpError(response) {
                const status = response.status;
                const contentType = response.headers.get('content-type');

                let message = `Request failed with status ${status}`;
                let extra = {};

                try {
                    if (contentType?.includes('application/json')) {
                        const data = await response.json();
                        message = data.message ?? message;
                        extra = data;
                    } else {
                        const text = await response.text();
                        message = text || message;
                    }
                } catch {
                }

                return createError(message, status, extra);
            }
        });

    </script>

    {!! ($modalLink??'') !!}
@endsection
