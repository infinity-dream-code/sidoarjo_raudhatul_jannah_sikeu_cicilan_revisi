@extends('layouts.admin_new')
@section('title', $dataTitle ?? $mainTitle ?? $title ?? '')
@section('style')
    <link rel="stylesheet" href="{{ asset('main/libs/select2/select2.css') }}">
    <link rel="stylesheet" href="{{ asset('main/libs/datatables-bs5/datatables.bootstrap5.css') }}">
    <style>
        .kirim-wa-toolbar {
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
        #kirim_wa_table th, #kirim_wa_table td {
            vertical-align: middle;
            white-space: nowrap;
        }
    </style>
@endsection

@section('content')
    <h3 class="page-heading d-flex text-gray-900 fw-bold flex-column justify-content-center my-0">
        {{ $dataTitle ?? $mainTitle ?? '' }}
    </h3>
    <ul class="breadcrumb breadcrumb-style2">
        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Beranda</a></li>
        <li class="breadcrumb-item">{{ $title ?? 'Keuangan' }}</li>
        <li class="breadcrumb-item active">{{ $dataTitle ?? 'Kirim WA Tagihan' }}</li>
    </ul>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Kirim WA Tagihan</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-timeline mb-4">
                <li class="list-group-item list-group-timeline-danger">
                    <strong>Pilih template WA dulu sebelum mengirim.</strong>
                </li>
                <li class="list-group-item list-group-timeline-danger">
                    <strong>Siswa tanpa nomor WhatsApp tidak akan menerima pesan.</strong>
                </li>
            </ul>

            <form id="filter-form">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="template_id">Template WA</label>
                        <select class="form-select" id="template_id" name="template_id" data-control="select2" required>
                            <option value="">Pilih template</option>
                            @forelse(($templates ?? []) as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->nama }} ({{ $tpl->kode }})</option>
                            @empty
                                <option value="0">Template default</option>
                            @endforelse
                        </select>
                        <div class="form-text">Isi template diatur di Master Data → Template WA.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="filter_siswa">NIS / Nama</label>
                        <input type="text" class="form-control" id="filter_siswa" name="filter[siswa]"
                               placeholder="NIS / Nama / No VA">
                    </div>
                    <div class="col-md-4">
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
                    <div class="col-md-4">
                        <label class="form-label" for="filter_tahun">Tahun Akademik</label>
                        <select class="form-select" id="filter_tahun" name="filter[tahun_akademik]" data-control="select2">
                            <option value="all">Semua</option>
                            @foreach(($thnAka ?? []) as $item)
                                <option value="{{ $item->thn_aka }}">{{ $item->thn_aka }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ri-search-line me-1"></i> Cari
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="reset" class="btn btn-outline-secondary w-100" id="btn-reset-filter">
                            <i class="ri-reset-left-line me-1"></i> Reset
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover w-100" id="kirim_wa_table">
                    <thead class="table-light">
                    <tr>
                        <th style="width:42px;">
                            <input type="checkbox" class="form-check-input" id="check-all" title="Pilih semua di halaman">
                        </th>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>No WA</th>
                        <th>Tahun</th>
                        <th>Nama Tagihan</th>
                        <th class="text-end">Jumlah</th>
                        <th class="text-end">Sisa</th>
                        <th>Status No WA</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="kirim-wa-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="fw-semibold">
                Terpilih: <span id="selected-count" class="text-primary">0</span> tagihan
                (<span id="selected-siswa">0</span> siswa)
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" id="btn-clear-selection">Kosongkan Pilihan</button>
                <button type="button" class="btn btn-success" id="btn-kirim-massal" disabled>
                    <i class="ri-whatsapp-line me-1"></i> Kirim Massal
                </button>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{ asset('main/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('main/libs/datatables-bs5/datatables-bootstrap5.js') }}"></script>
    <script>
        (function () {
            const dataUrl = @json($dataUrl);
            const sendUrl = @json($sendUrl);
            const selectedMap = new Map();

            function currentCsrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            }

            $('[data-control="select2"]').each(function () {
                const $this = $(this);
                $this.wrap('<div class="position-relative"></div>').select2({
                    dropdownParent: $this.parent(),
                    width: '100%',
                    placeholder: $this.data('placeholder') || 'Pilih',
                    allowClear: !$this.prop('required'),
                });
            });

            function formatRp(n) {
                const v = Number(n) || 0;
                return 'Rp ' + v.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            function uniqueSiswaCount() {
                const ids = new Set();
                selectedMap.forEach((row) => {
                    if (row && row.CUSTID != null) ids.add(String(row.CUSTID));
                });
                return ids.size;
            }

            function syncSelectedCount() {
                const count = selectedMap.size;
                $('#selected-count').text(count);
                $('#selected-siswa').text(uniqueSiswaCount());
                $('#btn-kirim-massal').prop('disabled', count === 0);
            }

            function syncCheckAll() {
                const boxes = $('#kirim_wa_table tbody .row-check');
                const checked = boxes.filter(':checked');
                const all = document.getElementById('check-all');
                all.checked = boxes.length > 0 && checked.length === boxes.length;
                all.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }

            function selectedTemplateId() {
                return $('#template_id').val();
            }

            function buildTagihanPayload(rows) {
                const tagihan = {};
                rows.forEach((row) => {
                    const custId = String(row.CUSTID ?? '');
                    const aa = String(row.AA ?? '');
                    if (!custId || !aa) return;
                    if (!tagihan[custId]) tagihan[custId] = [];
                    if (!tagihan[custId].includes(aa)) tagihan[custId].push(aa);
                });
                return tagihan;
            }

            function sendWhatsapp(tagihan) {
                const templateId = selectedTemplateId();
                if (templateId === '' || templateId === null) {
                    warningAlert('Pilih template WA terlebih dahulu.');
                    return;
                }

                const siswaCount = Object.keys(tagihan).length;
                if (siswaCount === 0) {
                    warningAlert('Pilih minimal 1 tagihan.');
                    return;
                }
                if (siswaCount > 100) {
                    warningAlert('Jumlah siswa yang dipilih tidak boleh lebih dari 100.');
                    return;
                }

                loadingAlert('Mengirim pesan.<br><span class="text-danger">Jangan menutup atau memuat ulang halaman.</span>');

                fetch(sendUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': currentCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        template_id: Number(templateId),
                        tagihan: tagihan,
                    }),
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
                        successAlert(data.message || 'Pesan masuk antrian.');
                    })
                    .catch((err) => {
                        if (err.status === 419) {
                            errorAlert('Sesi/CSRF sudah habis. Silakan muat ulang halaman lalu coba lagi.');
                            return;
                        }
                        errorAlert(err.message || 'Gagal mengirim WhatsApp.');
                    });
            }

            const table = $('#kirim_wa_table').DataTable({
                processing: true,
                serverSide: true,
                searching: true,
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [[2, 'asc']],
                ajax: {
                    url: dataUrl,
                    data: function (d) {
                        d.filter = {
                            siswa: $('#filter_siswa').val(),
                            kelas: $('#filter_kelas').val(),
                            tahun_akademik: $('#filter_tahun').val(),
                        };
                    },
                },
                columns: [
                    {
                        data: 'AA',
                        orderable: false,
                        searchable: false,
                        className: 'text-center',
                        render: function (data) {
                            const checked = selectedMap.has(String(data)) ? 'checked' : '';
                            return `<input type="checkbox" class="form-check-input row-check" value="${data}" ${checked}>`;
                        },
                    },
                    { data: 'NOCUST', defaultContent: '-' },
                    { data: 'NMCUST', defaultContent: '-' },
                    { data: 'NO_WA', defaultContent: '-' },
                    { data: 'BTA', defaultContent: '-' },
                    { data: 'BILLNM', defaultContent: '-' },
                    {
                        data: 'BILLAM',
                        className: 'text-end',
                        render: (d) => formatRp(d),
                    },
                    {
                        data: 'PAYMENTLEFT',
                        className: 'text-end',
                        render: (d) => formatRp(d),
                    },
                    {
                        data: 'has_wa',
                        className: 'text-center',
                        orderable: false,
                        render: function (d) {
                            return d
                                ? '<span class="badge bg-success">Ada</span>'
                                : '<span class="badge bg-danger">Kosong</span>';
                        },
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-center',
                        render: function (d, t, row) {
                            const disabled = row.has_wa ? '' : 'disabled';
                            return `<button type="button" class="btn btn-sm btn-success btn-kirim-satu" ${disabled}>
                                <i class="ri-whatsapp-line me-1"></i> Kirim
                            </button>`;
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
                    $('#kirim_wa_table tbody tr').each(function () {
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

            $('#btn-reset-filter').on('click', function () {
                setTimeout(function () {
                    $('#filter_kelas').val('all').trigger('change');
                    $('#filter_tahun').val('all').trigger('change');
                    table.ajax.reload();
                }, 0);
            });

            $('#kirim_wa_table').on('change', '.row-check', function () {
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
                $('#kirim_wa_table tbody .row-check').each(function () {
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
                $('#kirim_wa_table .row-check').prop('checked', false);
                $('#kirim_wa_table tbody tr').removeClass('row-selected');
                syncSelectedCount();
                syncCheckAll();
            });

            $('#btn-kirim-massal').on('click', function () {
                sendWhatsapp(buildTagihanPayload(Array.from(selectedMap.values())));
            });

            $('#kirim_wa_table').on('click', '.btn-kirim-satu', function () {
                const row = table.row($(this).closest('tr')).data();
                if (!row) return;
                sendWhatsapp(buildTagihanPayload([row]));
            });
        })();
    </script>
@endsection
