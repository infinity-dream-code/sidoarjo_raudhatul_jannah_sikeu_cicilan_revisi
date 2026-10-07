@extends('layouts.admin_new')
@section('title', $dataTitle ?? $mainTitle ?? $title ?? '')
@section('style')
    <link rel="stylesheet" href="{{ asset('main/libs/select2/select2.css') }}">
    <link rel="stylesheet" href="{{ asset('main/libs/datatables-bs5/datatables.bootstrap5.css') }}">
    <style>
        .expired-toolbar {
            position: sticky;
            bottom: 0;
            z-index: 5;
            background: #fff;
            border-top: 1px solid #e7e7e7;
            padding: .85rem 1rem;
            box-shadow: 0 -4px 16px rgba(0,0,0,.06);
        }
        tr.row-selected > td {
            background: #eef4ff !important;
        }
        .badge-overdue {
            font-size: .72rem;
            font-weight: 700;
        }
        #expired_table th, #expired_table td {
            vertical-align: middle;
            white-space: nowrap;
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
        <li class="breadcrumb-item active">{{ $dataTitle ?? 'Perpanjang Expired' }}</li>
    </ul>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1">Pilih Tagihan Expired</h5>
                <div class="text-muted small">Centang tagihan yang ingin diperpanjang, atau perpanjang otomatis semua.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-info" id="btn-auto-all">
                    <i class="ri-calendar-check-line me-1"></i> Perpanjang Otomatis Semua
                </button>
                <a href="{{ $backUrl }}" class="btn btn-outline-secondary">
                    <i class="ri-arrow-left-line me-1"></i> Kembali ke Data Tagihan
                </a>
            </div>
        </div>
        <div class="card-body">
            <form id="filter-form">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label" for="filter_expired">Expired</label>
                        <select class="form-select" id="filter_expired" name="filter[expired]" data-control="select2">
                            <option value="ya" selected>Ya</option>
                            <option value="tidak">Tidak</option>
                            <option value="all">Semua</option>
                        </select>
                        <div class="form-text">Dicek ke <code>ExpDate</code> vs hari ini.</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter_periode">Periode</label>
                        <select class="form-select" id="filter_periode" name="filter[periode]" data-control="select2">
                            <option value="all">Semua</option>
                            @foreach(($periode ?? []) as $item)
                                <option value="{{ $item }}">{{ $item }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter_kelas">Kelas</label>
                        <select class="form-select" id="filter_kelas" name="filter[kelas]" data-control="select2">
                            <option value="all">Semua</option>
                            @foreach(($kelas ?? []) as $item)
                                <option value="{{ $item->unit }}~~{{ $item->jenjang }}~~{{ $item->kelas }}">
                                    {{ $item->unit }} - {{ $item->jenjang }} {{ $item->kelas }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter_siswa">Siswa</label>
                        <input type="text" class="form-control" id="filter_siswa" name="filter[siswa]"
                               placeholder="NIS / Nama">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ri-search-line me-1"></i> Cari
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover w-100" id="expired_table">
                    <thead class="table-light">
                    <tr>
                        <th style="width:42px;">
                            <input type="checkbox" class="form-check-input" id="check-all" title="Pilih semua di halaman">
                        </th>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Kelas</th>
                        <th>Nama Tagihan</th>
                        <th>Periode</th>
                        <th class="text-end">Sisa</th>
                        <th>Expired Date</th>
                        <th>Status Expired</th>
                        <th>Terlambat</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="expired-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="fw-semibold">
                Terpilih: <span id="selected-count" class="text-primary">0</span> tagihan
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" id="btn-clear-selection">Kosongkan Pilihan</button>
                <button type="button" class="btn btn-info" id="btn-open-perpanjang" disabled>
                    <i class="ri-calendar-schedule-line me-1"></i> Perpanjang Terpilih
                </button>
            </div>
        </div>
    </div>

    <form id="form-perpanjang-exp">
        <div class="modal fade" id="modal-perpanjang-exp" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Perpanjang Expired Date</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <div>Jumlah tagihan: <strong id="perpanjang-count">0</strong></div>
                            <div class="small mt-1" id="perpanjang-preview-list"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mode Perpanjang</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="mode-auto" value="auto" checked>
                                <label class="form-check-label" for="mode-auto">
                                    Otomatis tanggal 20
                                    <span class="d-block small text-muted">
                                        Hari ≤ 20 → tgl 20 bulan ini; hari &gt; 20 → tgl 20 bulan depan.
                                        Target: <strong>{{ $autoExpDateLabel ?? '-' }}</strong>
                                    </span>
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="mode" id="mode-custom" value="custom">
                                <label class="form-check-label" for="mode-custom">Set tanggal sendiri</label>
                            </div>
                        </div>
                        <div class="mb-0" id="custom-exp-wrap" style="display:none;">
                            <label class="form-label" for="custom_exp_date">Tanggal Expired Baru</label>
                            <input type="date" class="form-control" id="custom_exp_date" value="{{ $autoExpDate ?? '' }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-info">Simpan Perpanjang</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('script')
    <script src="{{ asset('main/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('main/libs/datatables-bs5/datatables-bootstrap5.js') }}"></script>
    <script>
        (function () {
            const dataUrl = @json($dataUrl);
            const storeUrl = @json($storeUrl);
            const autoAllUrl = @json($autoAllUrl ?? '');
            const AUTO_EXP_DATE = @json($autoExpDate ?? '');
            const selectedMap = new Map();
            const modalEl = document.getElementById('modal-perpanjang-exp');
            const modal = new bootstrap.Modal(modalEl);

            function currentCsrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            }

            $('[data-control="select2"]').each(function () {
                const $this = $(this);
                $this.wrap('<div class="position-relative"></div>').select2({
                    dropdownParent: $this.parent(),
                    width: '100%',
                });
            });

            function formatRp(n) {
                const v = Number(n) || 0;
                return 'Rp. ' + v.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            function syncSelectedCount() {
                const count = selectedMap.size;
                $('#selected-count').text(count);
                $('#btn-open-perpanjang').prop('disabled', count === 0);
            }

            function syncCheckAll() {
                const boxes = $('#expired_table tbody .row-check');
                const checked = boxes.filter(':checked');
                const all = document.getElementById('check-all');
                all.checked = boxes.length > 0 && checked.length === boxes.length;
                all.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }

            const table = $('#expired_table').DataTable({
                processing: true,
                serverSide: true,
                searching: true,
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [],
                ajax: {
                    url: dataUrl,
                    data: function (d) {
                        d.filter = {
                            expired: $('#filter_expired').val(),
                            periode: $('#filter_periode').val(),
                            kelas: $('#filter_kelas').val(),
                            siswa: $('#filter_siswa').val(),
                        };
                    },
                },
                columns: [
                    {
                        data: 'AA',
                        orderable: false,
                        searchable: false,
                        className: 'text-center',
                        render: function (data, type, row) {
                            const checked = selectedMap.has(String(data)) ? 'checked' : '';
                            return `<input type="checkbox" class="form-check-input row-check" value="${data}" ${checked}>`;
                        },
                    },
                    { data: 'NOCUST', defaultContent: '-' },
                    { data: 'NMCUST', defaultContent: '-' },
                    {
                        data: null,
                        render: (d, t, row) => `${row.DESC02 || ''} ${row.DESC03 || ''}`.trim() || '-',
                    },
                    { data: 'BILLNM', defaultContent: '-' },
                    { data: 'BILLAC', defaultContent: '-' },
                    {
                        data: 'PAYMENTLEFT',
                        className: 'text-end',
                        render: (d) => formatRp(d),
                    },
                    {
                        data: 'ExpDate',
                        defaultContent: '-',
                    },
                    {
                        data: 'is_expired',
                        className: 'text-center',
                        render: function (d, t, row) {
                            if (d) {
                                return '<span class="badge bg-danger">Ya</span>';
                            }
                            return '<span class="badge bg-success">Tidak</span>';
                        },
                    },
                    {
                        data: 'days_overdue',
                        render: (d) => {
                            const n = Number(d) || 0;
                            if (n <= 0) return '-';
                            return `<span class="badge bg-label-warning badge-overdue">${n} hari</span>`;
                        },
                    },
                ],
                language: {
                    processing: 'Memuat...',
                    search: 'Cari:',
                    lengthMenu: 'Tampil _MENU_',
                    info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                    infoEmpty: 'Tidak ada data',
                    zeroRecords: 'Tidak ada data tagihan',
                    paginate: { previous: 'Sebelumnya', next: 'Selanjutnya' },
                },
                drawCallback: function () {
                    $('#expired_table tbody tr').each(function () {
                        const id = String($(this).find('.row-check').val() || '');
                        $(this).toggleClass('row-selected', selectedMap.has(id));
                    });
                    syncCheckAll();
                },
            });

            $('#filter-form').on('submit', function (e) {
                e.preventDefault();
                table.ajax.reload();
            });

            $('#expired_table').on('change', '.row-check', function () {
                const id = String(this.value);
                const row = table.row($(this).closest('tr')).data();
                if (this.checked) {
                    selectedMap.set(id, row);
                    $(this).closest('tr').addClass('row-selected');
                } else {
                    selectedMap.delete(id);
                    $(this).closest('tr').removeClass('row-selected');
                }
                syncSelectedCount();
                syncCheckAll();
            });

            $('#check-all').on('change', function () {
                const checked = this.checked;
                $('#expired_table tbody .row-check').each(function () {
                    this.checked = checked;
                    const id = String(this.value);
                    const row = table.row($(this).closest('tr')).data();
                    if (checked) {
                        selectedMap.set(id, row);
                        $(this).closest('tr').addClass('row-selected');
                    } else {
                        selectedMap.delete(id);
                        $(this).closest('tr').removeClass('row-selected');
                    }
                });
                syncSelectedCount();
                syncCheckAll();
            });

            $('#btn-clear-selection').on('click', function () {
                selectedMap.clear();
                $('#expired_table .row-check').prop('checked', false);
                $('#expired_table tbody tr').removeClass('row-selected');
                syncSelectedCount();
                syncCheckAll();
            });

            $('input[name="mode"]').on('change', function () {
                $('#custom-exp-wrap').toggle(this.value === 'custom');
            });

            $('#btn-open-perpanjang').on('click', function () {
                if (!selectedMap.size) {
                    warningAlert('Pilih minimal 1 tagihan.');
                    return;
                }
                const rows = Array.from(selectedMap.values());
                $('#perpanjang-count').text(rows.length);
                const preview = rows.slice(0, 8).map((r) => {
                    return `${r.NMCUST || '-'} — ${r.BILLNM || '-'} (exp: ${r.ExpDate || '-'})`;
                });
                const more = rows.length > 8
                    ? `<div class="text-muted">+${rows.length - 8} tagihan lainnya</div>`
                    : '';
                $('#perpanjang-preview-list').html(preview.map((t) => `<div>${t}</div>`).join('') + more);
                $('#mode-auto').prop('checked', true);
                $('#custom-exp-wrap').hide();
                if (AUTO_EXP_DATE) $('#custom_exp_date').val(AUTO_EXP_DATE);
                modal.show();
            });

            $('#btn-auto-all').on('click', function () {
                if (!autoAllUrl) {
                    errorAlert('Endpoint perpanjang otomatis belum tersedia.');
                    return;
                }
                if (!confirm('Perpanjang otomatis SEMUA tagihan expired?\n\nTanggal baru: tgl 20 (hari ≤20 bulan ini, hari >20 bulan depan).')) {
                    return;
                }

                loadingAlert('Memperpanjang otomatis semua tagihan expired...');
                fetch(autoAllUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': currentCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({}),
                })
                    .then(async (res) => {
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw { status: res.status, message: data.message || res.statusText };
                        return data;
                    })
                    .then((data) => {
                        selectedMap.clear();
                        syncSelectedCount();
                        table.ajax.reload(null, false);
                        successAlert(data.message || 'Perpanjang otomatis selesai.');
                    })
                    .catch((err) => {
                        if (err.status === 419) {
                            errorAlert('Sesi/CSRF sudah habis. Silahkan muat ulang halaman lalu coba lagi.');
                            return;
                        }
                        errorAlert(err.message || 'Gagal perpanjang otomatis.');
                    });
            });

            $('#form-perpanjang-exp').on('submit', function (e) {
                e.preventDefault();
                const ids = Array.from(selectedMap.keys());
                if (!ids.length) {
                    warningAlert('Pilih minimal 1 tagihan.');
                    return;
                }
                const mode = $('input[name="mode"]:checked').val() || 'auto';
                const payload = { ids, mode };
                if (mode === 'custom') {
                    const expDate = $('#custom_exp_date').val();
                    if (!expDate) {
                        warningAlert('Isi tanggal expired baru.');
                        return;
                    }
                    payload.exp_date = expDate;
                }

                loadingAlert('Memperpanjang expired date...');
                fetch(storeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': currentCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                })
                    .then(async (res) => {
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw { status: res.status, message: data.message || res.statusText };
                        return data;
                    })
                    .then((data) => {
                        modal.hide();
                        selectedMap.clear();
                        syncSelectedCount();
                        table.ajax.reload(null, false);
                        successAlert(data.message || 'Berhasil diperpanjang.');
                    })
                    .catch((err) => {
                        if (err.status === 419) {
                            errorAlert('Sesi/CSRF sudah habis. Silahkan muat ulang halaman lalu coba lagi.');
                            return;
                        }
                        errorAlert(err.message || 'Gagal memperpanjang.');
                    });
            });
        })();
    </script>
@endsection
