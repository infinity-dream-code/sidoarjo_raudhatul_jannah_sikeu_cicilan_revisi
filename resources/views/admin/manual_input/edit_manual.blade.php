@extends('layouts.admin_new')
@section('title',$dataTitle??$mainTitle??$title??'')
@section('style')
    <link rel="stylesheet" href="{{asset('main/libs/datatables-bs5/datatables.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-responsive-bs5/responsive.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/datatables-buttons-bs5/buttons.bootstrap5.css')}}">
    <link rel="stylesheet" href="{{asset('main/libs/select2/select2.min.css')}}">
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
        <div class="card-header header-elements">
            <div class="card-title">
                <h5 class="mb-0 me-2">{{($dataTitle??$mainTitle)}}</h5>
            </div>
        </div>

        <div class="card-body">
            <fieldset class="form-fieldset">
                <div class="col-12 mb-3">
                    <label class="form-label" for="siswa">Siswa</label>
                    <select class="form-select" id="siswa" name="siswa"
                            data-control="select2-ajax-siswa"
                            data-placeholder="Masukkan NIS / No. Pendaftaran / Nama Siswa">
                    </select>
                </div>
            </fieldset>
        </div>
        <div class="card-datatable table-responsive text-nowrap px-5 card-siswa">
            <table class="table table-sm table-bordered table-hover"
                   id="table-siswa">
                <thead class="table-light">
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
        <div class="card-body px-0">
            <div class="row">
                <div class="col-12">
                    <div class="card-datatable table-responsive text-nowrap px-5">
                        <div class="card-header">
                            Tagihan yang dapat diedit
                            <small class="text-muted d-block">Hanya tagihan yang belum ada pembayaran. Jika sudah dicicil, tagihan pindah ke tabel bawah dan tidak dapat diubah.</small>
                        </div>
                        <div class="col-12">
                            <table class="table table-sm table-bordered table-hover"
                                   id="table-tagihan">
                                <thead class="table-light">
                                </thead>
                                <tbody>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-datatable table-responsive text-nowrap px-5">
                        <div class="card-header">
                            Tagihan yang sudah dibayar / dicicil
                            <small class="text-muted d-block">Tidak dapat diedit. Kolom Cicil Ke diambil dari jumlah cicilan (INSTALLMENT).</small>
                        </div>
                        <table class="table table-sm table-bordered table-hover"
                               id="table-tagihan-dibayar">
                            <thead class="table-light">
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <div class="w-100">
                <div class="row">
                    <div class="d-flex flex-column flex-md-row gap-4">
                        <button type="button" class="btn btn-secondary me-md-auto w-md-auto" id="btn-reset">
                            <span class="ri-reset-left-line me-2"></span>
                            Reset
                        </button>
                        <button class="btn btn-warning w-md-auto" type="button" id="btn-edit-tagihan">
                            <span class="ri-save-line me-2"></span>
                            Edit Tagihan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script src="{{asset('main/libs/datatables-bs5/datatables-bootstrap5.js')}}"></script>
    <script src="{{asset('js/datatableCustom/Datatable-0-4.min.js')}}?v=20260916-pdf-va"></script>
    <script src="{{asset('main/libs/select2/select2.min.js')}}"></script>
    <script src="{{asset('js/helper/formattedNumber.min.js')}}"></script>

    <script type="text/javascript" defer>
        let tableSiswa;
        let tableTagihan;
        let tableTagihanDibayar;
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let select2Param = '';

        function formatRupiahInput(value) {
            const number = parseInt(String(value ?? '').replace(/\D/g, '') || '0', 10);
            return number.toLocaleString('id-ID');
        }

        function parseRupiahInput(value) {
            return parseInt(String(value ?? '').replace(/\D/g, '') || '0', 10);
        }

        function getSelectedNominalFromRow(table, rowIndex) {
            const node = table.row(rowIndex).node();
            if (!node) return 0;
            const input = node.querySelector('.nominal-tagihan-input');
            if (input) {
                return parseRupiahInput(input.value);
            }
            const rowData = table.row(rowIndex).data();
            return parseRupiahInput(rowData?.BILLAM ?? 0);
        }

        function loadSiswaByCustId(custid) {
            if (!custid) {
                warningAlert('Siswa tidak valid');
                return;
            }

            $.ajax({
                url: '{{ route('admin.manual-input.edit-manual.get-siswa') }}',
                type: 'get',
                dataType: 'json',
                data: { custid: custid },
            }).done(function (response) {
                refreshDataTable(response.data ?? []);
                tableTagihan.clear().draw();
                tableTagihanDibayar.clear().draw();

                const siswa = response.data?.[0];
                if (!siswa?.CUSTID) {
                    warningAlert('Siswa tidak ditemukan');
                    return;
                }

                // Pilih lewat DataTables Select agar rows({selected:true}) terbaca
                // (centang checkbox manual tidak cukup — itu penyebab "Silahkan pilih 1 siswa")
                const rowIdx = tableSiswa.rows().indexes().toArray().find((idx) => {
                    return String(tableSiswa.row(idx).data()?.CUSTID) === String(siswa.CUSTID);
                });
                if (rowIdx !== undefined) {
                    tableSiswa.row(rowIdx).select();
                }
            }).fail(function (xhr) {
                if (xhr.status === 422) {
                    errorAlert('Gagal mendapat data siswa');
                } else if (xhr.status === 419) {
                    errorAlert('Permintaan gagal diproses. Silakan coba lagi.');
                } else if (xhr.status === 500) {
                    errorAlert('Tidak dapat terhubung ke server, Silahkan periksa koneksi internet anda');
                } else if (xhr.status === 403) {
                    errorAlert('Anda tidak memiliki izin untuk mengakses halaman ini');
                } else if (xhr.status === 404) {
                    errorAlert('Halaman tidak ditemukan');
                } else {
                    errorAlert('Terjadi kesalahan, silahkan coba memuat ulang halaman');
                }
            });
        }

        document.getElementById('btn-reset').addEventListener('click', function (e) {
            tableTagihan.clear().draw();
            tableTagihanDibayar.clear().draw();
            tableSiswa.clear().draw();
            $('#siswa').val(null).trigger('change');
        });

        document.getElementById('table-tagihan').addEventListener('blur', function (e) {
            if (!e.target.classList.contains('nominal-tagihan-input')) return;
            e.target.value = formatRupiahInput(e.target.value);
        }, true);

        document.getElementById('table-tagihan').addEventListener('click', function (e) {
            if (e.target.classList.contains('nominal-tagihan-input')) {
                const row = e.target.closest('tr');
                if (row) {
                    tableTagihan.row(row).select();
                }
            }
        });

        document.getElementById('btn-edit-tagihan').addEventListener('click', async function (e) {
            e.preventDefault();
            const selectedSiswa = tableSiswa.rows({selected: true}).data();
            if (!selectedSiswa[0]?.CUSTID) {
                warningAlert('Silahkan pilih 1 siswa')
                return;
            }

            const selectedTagihanDibayar = tableTagihanDibayar.rows({selected: true}).data();
            if (selectedTagihanDibayar[0]) {
                warningAlert('Tagihan yang sudah dibayar / dicicil tidak bisa diedit!')
                return;
            }

            const selectedTagihan = tableTagihan.rows({selected: true});
            if (!selectedTagihan.data()[0]) {
                warningAlert('Silahkan pilih tagihan yang bisa diedit!')
                return;
            }

            const selectedIndexes = selectedTagihan.indexes();
            const nominal = getSelectedNominalFromRow(tableTagihan, selectedIndexes[0]);
            if (!nominal || nominal <= 0) {
                warningAlert('Nominal tagihan tidak valid!');
                return;
            }

            const formData = new FormData();
            formData.append('siswa', selectedSiswa[0].CUSTID);
            formData.append('tagihan', selectedTagihan.data()[0].AA);
            formData.append('nominal', nominal);
            formData.append('_method', 'PUT');

            loadingAlert('Mengedit data...');
            const request = new Request(
                `{{route('admin.manual-input.edit-manual.edit-tagihan')}}`,
                {
                    method: "POST",
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: formData
                });

            let result = await submitForm(request);
            if (result) {
                await getTagihan(selectedSiswa[0].CUSTID, false);
                successAlert(result.message);
            }
        });

        async function getSiswa(siswa) {
            loadSiswaByCustId(siswa);
        }

        async function getTagihan(siswa, closeAlert = true) {
            loadingAlert('Memuat data...');
            const request = new Request(
                `{{route('admin.manual-input.edit-manual.get-tagihan')}}?siswa=${encodeURIComponent(siswa)}`,
                {
                    method: "get",
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                });

            fetch(request)
                .then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw {status: response.status, message: data.message || response.statusText};
                    }
                    return data;
                })
                .then(data => {
                    refreshTableTagihan(data);
                    if (closeAlert) {
                        Swal.close();
                    }
                })
                .catch(error => {
                    if (error.status === 422) {
                        const errors = error.error.error || error.error.errors;
                        errorAlert(error.error.message);
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
                        console.log(error)
                    }
                });
        }

        function cicilKeValue(row) {
            return Number(row?.CICIL_KE ?? row?.INSTALLMENT ?? 0) || 0;
        }

        function formatCicilKe(row) {
            const value = cicilKeValue(row);
            return value > 0 ? String(value) : '-';
        }

        function formatKeteranganTagihan(row) {
            const paidSt = Number(row?.PAIDST ?? 0);
            const cicilKe = cicilKeValue(row);
            const billPaid = Number(row?.BILLPAID ?? 0);

            if (paidSt === 1) {
                return cicilKe > 0
                    ? `Sudah lunas (cicil ke ${cicilKe}), tidak dapat diedit`
                    : 'Sudah lunas, tidak dapat diedit';
            }
            if (billPaid > 0 || cicilKe > 0) {
                return cicilKe > 0
                    ? `Sudah dicicil ke ${cicilKe}, tidak dapat diedit`
                    : 'Sudah dicicil, tidak dapat diedit';
            }
            return '-';
        }

        function refreshTableTagihan(newData = []) {
            const splitByPaidStatus = newData.reduce((acc, item) => {
                const paidSt = Number(item.PAIDST ?? 0);
                const billPaid = Number(item.BILLPAID ?? 0);
                const cicilKe = cicilKeValue(item);

                if (paidSt === 1 || billPaid > 0 || cicilKe > 0) {
                    acc.paid.push(item);
                } else {
                    acc.unpaid.push(item);
                }
                return acc;
            }, {paid: [], unpaid: []});

            tableTagihan.rows().deselect();
            tableTagihan.clear();
            tableTagihan.rows.add(splitByPaidStatus.unpaid);
            tableTagihan.draw();

            tableTagihanDibayar.rows().deselect();
            tableTagihanDibayar.clear();
            tableTagihanDibayar.rows.add(splitByPaidStatus.paid);
            tableTagihanDibayar.draw();
        }

        function refreshDataTable(newData = []) {
            tableSiswa.rows().deselect();
            tableSiswa.clear();
            tableSiswa.rows.add(newData);
            tableSiswa.draw();
        }

        async function submitForm(request, options = {}) {
            const controller = new AbortController();
            const timeout = options.timeout || 30000;

            const timeoutId = setTimeout(() => controller.abort(), timeout);

            try {
                const response = await fetch(request, {
                    ...options,
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                const contentType = response.headers.get('content-type');
                let data = null;

                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    data = await response.text();
                }

                if (!response.ok) {
                    const error = new Error(data?.message || `Gagal memproses permintaan! (${response.status})`);
                    error.status = response.status;
                    error.data = data;
                    throw error;
                }

                return data;
            } catch (error) {
                clearTimeout(timeoutId);

                if (error.name === 'AbortError') {
                    errorAlert('Permintaan terlalu lama ⏳, silakan coba lagi.');
                    return false;
                }

                if (error.status === 422) {
                    errorAlert(error.message);

                    const errors = error.data?.errors || error.data;
                    if (errors) {
                        processErrors(errors);
                    }

                    return false;
                }

                const errorMessages = {
                    401: 'Permintaan gagal diproses. Silakan coba lagi.',
                    403: 'Anda tidak memiliki izin untuk mengakses 😖',
                    404: 'Halaman tidak ditemukan 🧐',
                    405: 'Metode tidak valid 🧐 <br>Silakan coba lagi!',
                    419: 'Permintaan gagal diproses. Silakan coba lagi.',
                    429: 'Terlalu banyak permintaan 🙏 <br>Tunggu beberapa saat!',
                };

                errorAlert(
                    errorMessages[error.status] ||
                    error.message ||
                    'Terjadi kesalahan, silakan muat ulang halaman'
                );

                return false;
            }
        }

        document.addEventListener("DOMContentLoaded", function () {
            const languageKey = 'datatables_id_language';
            const languageUrl = '/js/datatableCustom/id.json';

            async function fetchLanguageFile() {
                try {
                    const response = await fetch(languageUrl);
                    if (!response.ok) throw new Error('Network response was not ok');
                    const data = await response.json();
                    localStorage.setItem(languageKey, JSON.stringify(data)); // Save to localStorage
                    return data;
                } catch (error) {
                    console.error('Error fetching language file:', error);
                    return null;
                }
            }

            let languageData = localStorage.getItem(languageKey);

            async function getDTLang() {
                if (!languageData) {
                    languageData = await fetchLanguageFile();
                } else {
                    languageData = JSON.parse(languageData);
                }
            }

            getDTLang();

            tableSiswa = $('#table-siswa').DataTable({
                columns: [
                    {data: 'CUSTID'},
                    {data: 'nis', title: 'NIS', render: function (data, type, row) {
                        return data || row.NOCUST || row.nocust || '-';
                    }},
                    {data: 'nama', title: 'Nama', render: function (data, type, row) {
                        return data || row.NMCUST || row.nmcust || '-';
                    }},
                    {data: 'unit', title: 'Unit', render: function (data, type, row) {
                        return data || row.CODE02 || '-';
                    }},
                    {data: 'kelas', title: 'Kelas'},
                    {data: 'kelompok', title: 'Kelompok'},
                    {data: 'angkatan', title: 'Angkatan'},
                ],
                columnDefs: [
                    {
                        targets: 0,
                        searchable: false,
                        orderable: false,
                        render: function (data) {
                            return `<input type="checkbox" id="siswa-checkbox-${data}" class="dt-checkboxes form-check-input checkbox-siswa" value="${data}">`;
                        },
                        checkboxes: {
                            selectRow: true,
                            selectAll: false,
                        },
                        className: 'text-center',
                    },
                ],
                language: {
                    ...languageData,
                    emptyTable: "Tidak ada siswa yang sesuai kriteria pencarian"
                },

                paging: true,
                serverSide: false,
                searching: false,
                lengthChange: false,
                pageLength: 10,
                order: [[1, 'desc']],
                select: 'single',
                scrollX: true,
            });

            tableSiswa.on('select', function (e, dt, type, indexes) {
                if (type !== 'row') return;
                const data = tableSiswa.row(indexes[0]).data();
                if (data?.CUSTID) {
                    getTagihan(data.CUSTID);
                }
            });

            tableSiswa.on('deselect', function () {
                if (tableSiswa.rows({selected: true}).any()) return;
                tableTagihan.clear().draw();
                tableTagihanDibayar.clear().draw();
            });

            tableTagihan = $('#table-tagihan').DataTable({
                columns: [
                    {data: 'AA'},
                    {data: 'BILLNM', title: 'Nama Tagihan'},
                    {data: 'BILLAC', title: 'Periode'},
                    {
                        data: 'CICIL_KE',
                        title: 'Cicil Ke',
                        className: 'text-center',
                        render: function (data, type, row) {
                            return formatCicilKe(row);
                        }
                    },
                    {
                        data: 'isINSTALLABLE',
                        title: 'Cicilan',
                        className: 'text-center',
                        render: function (data) {
                            return Number(data ?? 0) > 0 ? 'Ya' : 'Tidak';
                        }
                    },
                    {
                        data: 'BILLAM',
                        title: 'Nominal',
                        className: 'text-end',
                        render: function (data, type) {
                            const value = Number(data ?? 0);
                            if (type === 'display') {
                                return `<input type="text" class="form-control form-control-sm nominal-tagihan-input text-end" value="${formatRupiahInput(value)}" autocomplete="off">`;
                            }
                            return value;
                        }
                    },
                    {data: 'FUrutan', title: 'Urutan'},
                ],
                columnDefs: [
                    {
                        targets: 0,
                        searchable: false,
                        orderable: false,
                        render: function (data) {
                            return `<input type="checkbox" id="tagihan-checkbox-${data}" class="dt-checkboxes form-check-input checkbox" name="checkbox_tagihan" value="${data}">`;
                        },
                        checkboxes: {
                            selectRow: true,
                            selectAll: false,
                        },
                        className: 'text-center',
                    }
                ],
                language: {
                    ...languageData,
                    emptyTable: "Tidak ada tagihan yang dapat diedit"
                },

                paging: true,
                select: {style: 'single'},
                serverSide: false,
                searching: false,
                lengthChange: false,
                pageLength: 10,
                order: [[6, 'asc']],
                scrollX: true,
            });

            tableTagihanDibayar = $('#table-tagihan-dibayar').DataTable({
                columns: [
                    {data: 'BILLNM', title: 'Nama Tagihan'},
                    {data: 'BILLAC', title: 'Periode'},
                    {
                        data: 'CICIL_KE',
                        title: 'Cicil Ke',
                        className: 'text-center',
                        render: function (data, type, row) {
                            return formatCicilKe(row);
                        }
                    },
                    {
                        data: 'KETERANGAN',
                        title: 'Keterangan',
                        render: function (data, type, row) {
                            return formatKeteranganTagihan(row);
                        }
                    },
                    {
                        data: 'BILLAM',
                        title: 'Nominal',
                        className: 'text-end',
                        render: function (data) {
                            const value = Number(data);
                            if (!Number.isFinite(value)) {
                                return 'Rp. 0';
                            }
                            const formatted = $.fn.dataTable
                                .render
                                .number('.', ',', 0, 'Rp. ')
                                .display(Math.abs(value));
                            return value < 0 ? `Rp. -${formatted.replace('Rp. ', '')}` : formatted;
                        }
                    },
                    {
                        data: 'BILLPAID',
                        title: 'Terbayar',
                        className: 'text-end',
                        render: function (data) {
                            const value = Number(data ?? 0);
                            if (!Number.isFinite(value)) {
                                return 'Rp. 0';
                            }
                            return $.fn.dataTable.render.number('.', ',', 0, 'Rp. ').display(Math.abs(value));
                        }
                    },
                    {
                        data: 'PAYMENTLEFT',
                        title: 'Sisa',
                        className: 'text-end',
                        render: function (data) {
                            const value = Number(data ?? 0);
                            if (!Number.isFinite(value)) {
                                return 'Rp. 0';
                            }
                            return $.fn.dataTable.render.number('.', ',', 0, 'Rp. ').display(Math.abs(value));
                        }
                    },
                    {
                        data: 'PAIDST',
                        title: 'Status',
                        className: 'text-center',
                        render: function (data, type, row) {
                            if (Number(data ?? 0) === 1) {
                                return 'Lunas';
                            }
                            if (Number(row.BILLPAID ?? 0) > 0 || cicilKeValue(row) > 0) {
                                return 'Sudah dicicil';
                            }
                            return 'Belum lunas';
                        }
                    },
                    {data: 'FUrutan', title: 'Urutan'},
                ],
                language: {
                    ...languageData,
                    emptyTable: "Tidak ada tagihan yang sudah dibayar / dicicil"
                },

                paging: true,
                select: false,
                serverSide: false,
                searching: false,
                lengthChange: false,
                pageLength: 10,
                order: [[8, 'asc']],
                scrollX: true,
            });

            $('[data-control="select2-ajax-siswa"]').select2({
                allowClear: true,
                placeholder: $('#siswa').data('placeholder'),
                ajax: {
                    url: '{{ route('admin.master-data.data-siswa.get-siswa-select2') }}',
                    dataType: 'json',
                    delay: 300,
                    data: function (params) {
                        select2Param = params.term;
                        return {
                            term: params.term,
                        };
                    },
                    processResults: function (data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                language: {
                    inputTooShort: function () {
                        return "Masukkan NIS atau No. Pendaftaran atau Nama Siswa";
                    },
                    noResults: function () {
                        let w = $.isNumeric(select2Param) ? 'NIS' : 'Nama';
                        return "Siswa dengan " + w + ": <span class='bg-label-danger'><b>" + select2Param + "</b></span> tidak ditemukan!";
                    },
                    searching: function () {
                        return "Mencari Siswa ......";
                    }
                },
                escapeMarkup: function (markup) {
                    return markup;
                },
                minimumInputLength: 4,
            }).on('select2:selecting', function (e) {
                if (e.params.args.data.id === '') {
                    e.preventDefault();
                }
            }).on('select2:select', function (e) {
                loadSiswaByCustId(e.params.data.id);
            }).on('select2:clear', function () {
                tableSiswa.clear().draw();
                tableTagihan.clear().draw();
                tableTagihanDibayar.clear().draw();
            });
        });

    </script>
@endsection

