@extends('layouts.admin_new')
@section('title', $dataTitle ?? $mainTitle ?? $title ?? '')
@section('style')
    <link rel="stylesheet" href="{{ asset('main/libs/select2/select2.min.css') }}">
    <style>
        .hasil-box {
            border: 1px solid #d9deff;
            background: #f7f8ff;
            border-radius: .75rem;
            padding: 1rem;
        }
        #tagihan-table input[type="number"] {
            min-width: 120px;
        }
    </style>
@endsection

@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">
        {{ ($mainTitle ?? '') . (isset($dataTitle) && $dataTitle !== ($mainTitle ?? '') ? ' - ' . $dataTitle : '') }}
    </h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Beranda</a></li>
        <li class="breadcrumb-item">{{ $title ?? 'Keuangan' }}</li>
        <li class="breadcrumb-item">{{ $mainTitle ?? 'Tagihan Siswa' }}</li>
        <li class="breadcrumb-item active">{{ $dataTitle ?? 'Aktifasi Pembayaran Bank' }}</li>
    </ul>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Pilih Siswa</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Siswa</label>
                    <select id="siswa-select" class="form-select" data-control="select2-ajax-siswa"
                            data-placeholder="Masukkan NIS / No. Pendaftaran / Nama Siswa"></select>
                </div>
                <div class="col-md-4">
                    <button type="button" id="btn-muat-tagihan" class="btn btn-primary w-100">
                        Muat Tagihan
                    </button>
                </div>
            </div>

            <div id="siswa-info" class="row g-3 mt-3 d-none">
                <div class="col-md-3"><div class="small text-muted">Nama</div><div id="info-nama" class="fw-semibold">-</div></div>
                <div class="col-md-2"><div class="small text-muted">NIS</div><div id="info-nis" class="fw-semibold">-</div></div>
                <div class="col-md-2"><div class="small text-muted">Kelas</div><div id="info-kelas" class="fw-semibold">-</div></div>
                <div class="col-md-2"><div class="small text-muted">No WA</div><div id="info-wa" class="fw-semibold">-</div></div>
                <div class="col-md-3">
                    <div class="small text-muted">VA Close / VA Open</div>
                    <div class="fw-semibold small">
                        <div>Close: <span id="info-nova-close">-</span></div>
                        <div>Open: <span id="info-nova-open">-</span></div>
                    </div>
                </div>
            </div>

            <div id="aktifasi-banner" class="alert alert-primary d-none mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>Aktifasi aktif ditemukan.</strong>
                    <span class="d-block small" id="aktifasi-banner-text">Siswa ini sudah punya instruksi pembayaran VA.</span>
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="btn-lihat-aktifasi">
                    Lihat Aktifasi
                </button>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Tagihan yang akan dibayar</h5>
            <button type="button" id="btn-generate" class="btn btn-success" disabled>
                Aktifkan Pembayaran Bank
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="tagihan-table">
                    <thead>
                    <tr>
                        <th class="text-center" style="width:40px">
                            <input type="checkbox" class="form-check-input" id="check-all">
                        </th>
                        <th>Nama Tagihan</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Sudah Dibayar</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-center">Cicil?</th>
                        <th>VA</th>
                        <th>Exp Date</th>
                        <th style="min-width:140px">Bayar</th>
                    </tr>
                    </thead>
                    <tbody id="tagihan-body">
                    <tr>
                        <td colspan="9" class="text-center text-muted">Pilih siswa lalu klik Muat Tagihan.</td>
                    </tr>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="8" class="text-end">Total dipilih</th>
                        <th class="text-end" id="total-bayar">Rp 0</th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div id="hasil-panel" class="card d-none">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Hasil Aktifasi</h5>
            <span class="badge bg-label-primary">Aktif</span>
        </div>
        <div class="card-body">
            <div class="hasil-box mb-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted">Nomor VA</div>
                        <div id="hasil-nova" class="fs-5 fw-bold text-primary">-</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Total Bayar</div>
                        <div id="hasil-total" class="fs-5 fw-bold">-</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Batas VA / Dibuat</div>
                        <div id="hasil-exp" class="fw-semibold">-</div>
                        <div class="small text-muted" id="hasil-created"></div>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="small text-muted mb-1">Link cara bayar</div>
                    <div class="input-group input-group-sm">
                        <input type="text" id="hasil-link" class="form-control" readonly>
                        <a id="btn-buka-link" href="#" target="_blank" class="btn btn-outline-secondary">Buka</a>
                    </div>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Tagihan</th>
                        <th class="text-end">Nominal Bayar</th>
                    </tr>
                    </thead>
                    <tbody id="hasil-items"></tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a id="btn-pdf" href="#" target="_blank" class="btn btn-outline-danger">
                    <i class="ri-file-pdf-line me-1"></i>Unduh PDF
                </a>
                <button type="button" id="btn-salin-link" class="btn btn-primary">
                    <i class="ri-file-copy-line me-1"></i>Salin Link
                </button>
                <a id="btn-kirim-wa" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-success d-none">
                    <i class="ri-whatsapp-line me-1"></i>Kirim WA
                </a>
                <button type="button" id="btn-kirim-wa-disabled" class="btn btn-outline-success">
                    <i class="ri-whatsapp-line me-1"></i>Kirim WA
                </button>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{ asset('main/libs/select2/select2.min.js') }}"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let selectedSiswaData = null;
        let currentCustId = null;
        let currentAktifasi = null;

        function formatRp(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function initSiswaSelect2Ajax() {
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
                placeholder: $siswaAjax.data('placeholder') || 'Cari siswa',
                ajax: {
                    url: '{{ route('admin.master-data.data-siswa.get-siswa-select2') }}',
                    dataType: 'json',
                    delay: 300,
                    data: params => ({ term: params.term }),
                    processResults: data => ({ results: Array.isArray(data) ? data : [] }),
                    cache: true
                },
                minimumInputLength: 3,
                escapeMarkup: m => m,
            }).on('select2:select', function (e) {
                selectedSiswaData = e.params.data || null;
            }).on('select2:clear', function () {
                selectedSiswaData = null;
                currentCustId = null;
                currentAktifasi = null;
                $('#siswa-info').addClass('d-none');
                $('#aktifasi-banner').addClass('d-none');
                $('#tagihan-body').html('<tr><td colspan="8" class="text-center text-muted">Pilih siswa lalu klik Muat Tagihan.</td></tr>');
                $('#btn-generate').prop('disabled', true);
                $('#hasil-panel').addClass('d-none');
                updateTotal();
            });
        }

        initSiswaSelect2Ajax();

        function fillSiswaInfo(siswa) {
            $('#info-nama').text(siswa.nmcust || '-');
            $('#info-nis').text(siswa.nocust || '-');
            $('#info-kelas').text(siswa.kelas || '-');
            $('#info-wa').text(siswa.no_wa || '-');
            $('#info-nova-close').text(siswa.nova_close || siswa.nova || '-');
            $('#info-nova-open').text(siswa.nova_open || '-');
            $('#siswa-info').removeClass('d-none');
        }

        function fillHasilPanel(data) {
            currentAktifasi = data || null;
            if (!data) {
                $('#hasil-panel').addClass('d-none');
                return;
            }
            $('#hasil-nova').text(data.nova || '-');
            $('#hasil-total').text(formatRp(data.total || 0));
            $('#hasil-exp').text(data.exp_date || '-');
            $('#hasil-created').text(data.created_at ? ('Dibuat: ' + data.created_at) : '');
            $('#hasil-link').val(data.share_url || '');
            $('#btn-buka-link').attr('href', data.share_url || '#');
            $('#btn-pdf').attr('href', data.pdf_url || '#');

            const itemsBody = $('#hasil-items').empty();
            (data.items || []).forEach((item, idx) => {
                itemsBody.append(`<tr><td>${idx + 1}</td><td>${item.nama || '-'}</td><td class="text-end">${formatRp(item.amount)}</td></tr>`);
            });

            if (data.wa_url) {
                $('#btn-kirim-wa').attr('href', data.wa_url).removeClass('d-none');
                $('#btn-kirim-wa-disabled').addClass('d-none');
            } else {
                $('#btn-kirim-wa').addClass('d-none');
                $('#btn-kirim-wa-disabled').removeClass('d-none');
            }

            $('#hasil-panel').removeClass('d-none');
        }

        function renderTagihan(rows) {
            const body = $('#tagihan-body');
            body.empty();
            if (!rows.length) {
                body.html('<tr><td colspan="9" class="text-center text-muted">Tidak ada tagihan aktif.</td></tr>');
                $('#btn-generate').prop('disabled', true);
                updateTotal();
                return;
            }

            rows.forEach((row) => {
                const installable = Number(row.isINSTALLABLE) === 1;
                const sisa = Number(row.sisa_tagihan || 0);
                const disabledPay = sisa <= 0;
                const vaLabel = installable ? 'Open' : 'Close';
                const vaBadge = installable ? 'bg-label-success' : 'bg-label-secondary';
                const tr = $(`
                    <tr data-aa="${row.AA}" data-sisa="${sisa}" data-installable="${installable ? 1 : 0}">
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input row-check" ${disabledPay ? 'disabled' : ''}>
                        </td>
                        <td>${row.nama_tagihan || '-'}</td>
                        <td class="text-end">${formatRp(row.total_tagihan)}</td>
                        <td class="text-end">${formatRp(row.sudah_dibayar)}</td>
                        <td class="text-end">${formatRp(sisa)}</td>
                        <td class="text-center">${installable ? '<span class="badge bg-label-success">Ya</span>' : '<span class="badge bg-label-secondary">Tidak</span>'}</td>
                        <td>
                            <span class="badge ${vaBadge}">${vaLabel}</span>
                            <div class="small text-muted">${row.nova || '-'}</div>
                        </td>
                        <td>${row.exp_date || '-'}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm bayar-input" min="1" max="${sisa}"
                                   value="${sisa}" ${(!installable || disabledPay) ? 'readonly' : 'disabled'}>
                        </td>
                    </tr>
                `);
                body.append(tr);
            });

            $('#btn-generate').prop('disabled', false);
            updateTotal();
        }

        function updateTotal() {
            let total = 0;
            $('#tagihan-body tr').each(function () {
                const checked = $(this).find('.row-check').is(':checked');
                if (!checked) return;
                total += Number($(this).find('.bayar-input').val() || 0);
            });
            $('#total-bayar').text(formatRp(total));
        }

        $('#tagihan-body').on('change', '.row-check', function () {
            const tr = $(this).closest('tr');
            const installable = Number(tr.data('installable')) === 1;
            const input = tr.find('.bayar-input');
            if ($(this).is(':checked')) {
                if (installable) input.prop('disabled', false);
            } else if (installable) {
                input.prop('disabled', true);
            }
            updateTotal();
        });

        $('#tagihan-body').on('input', '.bayar-input', updateTotal);

        $('#check-all').on('change', function () {
            const checked = $(this).is(':checked');
            $('#tagihan-body .row-check:not(:disabled)').each(function () {
                $(this).prop('checked', checked).trigger('change');
            });
        });

        async function fetchJson(url, options = {}) {
            const response = await fetch(url, options);
            const contentType = response.headers.get('content-type') || '';
            const data = contentType.includes('application/json') ? await response.json() : { message: await response.text() };
            if (!response.ok) {
                const err = new Error(data.message || 'Request gagal');
                err.data = data;
                throw err;
            }
            return data;
        }

        $('#btn-muat-tagihan').on('click', async function () {
            const custId = selectedSiswaData?.CUSTID || selectedSiswaData?.id;
            if (!custId) {
                errorAlert('Pilih siswa terlebih dahulu.');
                return;
            }
            loadingAlert('Memuat tagihan...');
            try {
                const data = await fetchJson(`{{ url('admin/keuangan/tagihan-siswa/aktifasi-pembayaran-bank/tagihan') }}/${custId}`);
                currentCustId = custId;
                fillSiswaInfo(data.siswa || {});
                renderTagihan(data.tagihan || []);
                currentAktifasi = data.aktifasi || null;
                if (currentAktifasi) {
                    $('#aktifasi-banner-text').text(`VA ${currentAktifasi.nova || '-'} · Total ${formatRp(currentAktifasi.total || 0)}`);
                    $('#aktifasi-banner').removeClass('d-none');
                    fillHasilPanel(currentAktifasi);
                } else {
                    $('#aktifasi-banner').addClass('d-none');
                    $('#hasil-panel').addClass('d-none');
                }
                Swal.close();
            } catch (e) {
                errorAlert(e.message || 'Gagal memuat tagihan.');
            }
        });

        $('#btn-lihat-aktifasi').on('click', function () {
            if (!currentAktifasi) {
                errorAlert('Belum ada aktifasi untuk siswa ini.');
                return;
            }
            fillHasilPanel(currentAktifasi);
            document.getElementById('hasil-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        $('#btn-generate').on('click', async function () {
            if (!currentCustId) {
                errorAlert('Muat tagihan siswa dulu.');
                return;
            }
            const items = [];
            $('#tagihan-body tr').each(function () {
                if (!$(this).find('.row-check').is(':checked')) return;
                items.push({
                    AA: Number($(this).data('aa')),
                    amount: Number($(this).find('.bayar-input').val() || 0),
                });
            });
            if (!items.length) {
                errorAlert('Centang minimal satu tagihan.');
                return;
            }

            loadingAlert('Mengaktifkan pembayaran bank...');
            try {
                const result = await fetchJson('{{ route('admin.keuangan.tagihan-siswa.aktifasi-pembayaran-bank.generate') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ custid: currentCustId, items }),
                });

                const data = result.data || {};
                currentAktifasi = data;
                $('#aktifasi-banner-text').text(`VA ${data.nova || '-'} · Total ${formatRp(data.total || 0)}`);
                $('#aktifasi-banner').removeClass('d-none');
                fillHasilPanel(data);
                successAlert(result.message || 'Berhasil diaktifkan.');
            } catch (e) {
                errorAlert(e.message || 'Gagal mengaktifkan pembayaran.');
            }
        });

        $('#btn-salin-link').on('click', async function () {
            const url = $('#hasil-link').val();
            if (!url) {
                errorAlert('Link belum tersedia.');
                return;
            }
            try {
                await navigator.clipboard.writeText(url);
                successAlert('Link cara bayar disalin.');
            } catch (e) {
                window.prompt('Salin link:', url);
            }
        });

        $('#btn-kirim-wa-disabled').on('click', function () {
            errorAlert('No WA orang tua belum diisi. Lengkapi No WA di Data Siswa dulu.');
        });
    </script>
@endsection
