<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Auth::routes([
    "register" => false,
]);
Route::get("/", [AuthController::class, "index"])->name("index");
Route::get('/cara-bayar/{token}', [\App\Http\Controllers\CaraBayarController::class, 'show'])
    ->where('token', '[A-Fa-f0-9]{64}')
    ->name('cara-bayar.show');
Route::get('/cara-bayar/{token}/pdf', [\App\Http\Controllers\CaraBayarController::class, 'pdf'])
    ->where('token', '[A-Fa-f0-9]{64}')
    ->name('cara-bayar.pdf');

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
Route::get('admin/session-ping', function () {
    try {
        if (!\Illuminate\Support\Facades\Auth::check()) {
            \App\Support\PersistentLogin::restore();
        }
    } catch (\Throwable) {
    }

    $csrf = null;
    try {
        $csrf = csrf_token();
    } catch (\Throwable) {
    }

    return response()->json([
        'ok' => \Illuminate\Support\Facades\Auth::check(),
        'csrf' => $csrf,
    ]);
})->name('admin.session-ping');
Route::get("/reload-captcha", [AuthController::class, "reloadCaptcha"])->name("reload-captcha");
Route::get("/reload-math-captcha", [\App\Http\Controllers\Auth\LoginController::class, "reloadMathCaptcha"])->name("reload-math-captcha");

Route::prefix("admin")
    ->name("admin.")
    ->middleware(["auth", "check.roles:admin"])
    ->group(function () {
        Route::get("/", [AdminController::class, "index"])->name("index");

        Route::prefix("master-data")->name("master-data.")->group(function () {
            Route::get("get-logo", function (\Illuminate\Http\Request $request) {
                $path = public_path(config("app.logo"));
                $mime = "image/jpeg";
                if (!file_exists($path)) {
                    return response()->json(["data" => null], 404);
                }
                $data = "data:{$mime};base64," . base64_encode(file_get_contents($path));
                return response()->json(["data" => $data]);
            })->name("get-logo");

            Route::prefix("master-kelas")
                ->name("master-kelas.")
                ->controller(\App\Http\Controllers\Admin\MasterData\MasterKelasController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::resource("", \App\Http\Controllers\Admin\MasterData\MasterKelasController::class)->parameters(["" => "id"]);
                });

            Route::prefix("tahun-pelajaran")
                ->name("tahun-pelajaran.")
                ->controller(\App\Http\Controllers\Admin\MasterData\TahunPelajaranController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                });
            Route::resource("tahun-pelajaran", \App\Http\Controllers\Admin\MasterData\TahunPelajaranController::class)->names("tahun-pelajaran");

            Route::prefix("master-tagihan")
                ->name("master-tagihan.")
                ->controller(\App\Http\Controllers\Admin\MasterData\MasterTagihanController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::get("", "index")->name("index");
                    Route::post("", "store")->name("store");
                });

            Route::prefix("export-import-data")
                ->name("export-import-data.")
                ->controller(\App\Http\Controllers\Admin\MasterData\ExportImportDataController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::post("validate-data", "validateData")->name("validate-data");
                    Route::get("clear-data", "clearData")->name("clear-data");
                    Route::resource("", \App\Http\Controllers\Admin\MasterData\ExportImportDataController::class)->parameters(["" => "id"]);
                });

            Route::prefix("data-siswa")
                ->name("data-siswa.")
                ->controller(\App\Http\Controllers\Admin\MasterData\DataSiswaController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::get("get-siswa", "getSiswa")->name("get-siswa");
                    Route::get("get-siswa-select2", "getSiswaSelect2")->name("get-siswa-select2");
                    Route::post("reset-login-android/{id}", "ResetLoginAndroid")->name("reset-login-android");
                    Route::post("reset-login-android-bulk", "resetLoginAndroidBulk")->name("reset-login-android-bulk");
                    Route::post("buat-link-tagihan/{id}", "buatLinkTagihan")->name("buat-link-tagihan");
                    Route::post("perbarui-link-tagihan/{id}", "perbaruiLinkTagihan")->name("perbarui-link-tagihan");
                    Route::post("set-status-siswa/{id}", "setStatusSiswa")->name("set-status-siswa");
                });
            Route::resource("data-siswa", \App\Http\Controllers\Admin\MasterData\DataSiswaController::class)->names("data-siswa");

            Route::prefix("setting-data-wa")
                ->name("setting-data-wa.")
                ->controller(\App\Http\Controllers\Admin\MasterData\SettingDataWaController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::post("validate-data", "validateData")->name("validate-data");
                    Route::get("clear-data", "clearData")->name("clear-data");
                    Route::resource("", \App\Http\Controllers\Admin\MasterData\SettingDataWaController::class)->parameters(["" => "id"]);
                });

            Route::prefix("pindah-kelas")
                ->name("pindah-kelas.")
                ->controller(\App\Http\Controllers\Admin\MasterData\PindahKelasController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::resource("", \App\Http\Controllers\Admin\MasterData\PindahKelasController::class)->parameters(["" => "id"]);
                });

            Route::prefix("template-wa")
                ->name("template-wa.")
                ->controller(\App\Http\Controllers\Admin\MasterData\TemplateWaController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                });
            Route::resource("template-wa", \App\Http\Controllers\Admin\MasterData\TemplateWaController::class)
                ->names("template-wa")
                ->except(["create", "show", "edit"]);
        });

        Route::prefix("keuangan")->name("keuangan.")->group(function () {
            Route::controller(\App\Http\Controllers\Admin\Keuangan\ManualPembayaranController::class)
                ->prefix("manual-pembayaran")->name("manual-pembayaran.")->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::get("get-tagihan", "getTagihan")->name("get-tagihan");
                    Route::get("cetak-tagihan", "cetakTagihan")->name("cetak-tagihan");
                    Route::get("cetak-tagihan-dibayar", "cetakPembayaran")->name("cetak-tagihan-dibayar");
                    Route::post("update-nocust", "updateNocust")->name("update-nocust");
                    Route::resource("", \App\Http\Controllers\Admin\Keuangan\ManualPembayaranController::class)->parameters(["" => "id"]);
                });

            Route::prefix("tagihan-siswa")->name("tagihan-siswa.")->group(function () {
                Route::prefix("data-tagihan")->name("data-tagihan.")->group(function () {
                    Route::controller(\App\Http\Controllers\Admin\Keuangan\TagihanSiswa\DataTagihanController::class)->group(function () {
                        Route::get("get-data", "getData")->name("get-data");
                        Route::get("get-column", "getColumn")->name("get-column");
                        Route::get("get-trans-log/{id}", "getTransLog")->name("get-trans-log");
                        Route::get("cetak-rekap", "cetak")->name("cetak-rekap");
                        Route::post("ubah-urutan/{id}", "ubahUrutan")->name("ubah-urutan");
                        Route::post("perpanjang-exp", "perpanjangExp")->name("perpanjang-exp");
                        Route::delete("hapus/{id}", "hapusTagihan")->name("hapus");
                        Route::get("cetak-kartu-siswa", "cetakKartuSiswa")->name("cetak-kartu-siswa");
                        Route::resource("", \App\Http\Controllers\Admin\Keuangan\TagihanSiswa\DataTagihanController::class)->parameters(["" => "id"]);
                    });
                });

                Route::prefix("upload-tagihan-excel")->name("upload-tagihan-excel.")->group(function () {
                    Route::controller(\App\Http\Controllers\Admin\Keuangan\TagihanSiswa\UploadTagihanExcelController::class)->group(function () {
                        Route::get("get-data", "getData")->name("get-data");
                        Route::get("get-column", "getColumn")->name("get-column");
                        Route::post("validate-excel", "validateExcel")->name("validate-excel");
                        Route::resource("", \App\Http\Controllers\Admin\Keuangan\TagihanSiswa\UploadTagihanExcelController::class)->parameters(["" => "id"]);
                    });
                });

                Route::prefix("perpanjang-expired")
                    ->name("perpanjang-expired.")
                    ->controller(\App\Http\Controllers\Admin\Keuangan\TagihanSiswa\PerpanjangExpiredController::class)
                    ->group(function () {
                        Route::get("", "index")->name("index");
                        Route::get("get-data", "getData")->name("get-data");
                        Route::post("store", "store")->name("store");
                        Route::post("auto-all", "autoAll")->name("auto-all");
                    });

                Route::prefix("aktifasi-pembayaran-bank")
                    ->name("aktifasi-pembayaran-bank.")
                    ->controller(\App\Http\Controllers\Admin\Keuangan\TagihanSiswa\AktifasiPembayaranBankController::class)
                    ->group(function () {
                        Route::get("", "index")->name("index");
                        Route::get("tagihan/{custid}", "tagihan")->name("tagihan");
                        Route::post("generate", "generate")->name("generate");
                        Route::get("pdf/{id}", "pdf")->name("pdf");
                    });
            });

            Route::prefix("penerimaan-siswa")->name("penerimaan-siswa.")->group(function () {
                Route::prefix("data-penerimaan")->name("data-penerimaan.")->group(function () {
                    Route::controller(\App\Http\Controllers\Admin\Keuangan\PenerimaanSiswa\DataPenerimaanController::class)->group(function () {
                        Route::get("get-data", "getData")->name("get-data");
                        Route::get("get-column", "getColumn")->name("get-column");
                        Route::get("get-trans-log/{id}", "getTransLog")->name("get-trans-log");
                        Route::post("get-trans-logs-bulk", "getTransLogsBulk")->name("get-trans-logs-bulk");
                        Route::get("cetak-rekap", "cetak")->name("cetak-rekap");
                        Route::get("cetak-rekap-new", "cetakNew")->name("cetak-rekap-new");
                        Route::get("cetak-kartu-siswa", "cetakKartuSiswa")->name("cetak-kartu-siswa");
                        Route::get("cetak-tagihan-dibayar", "cetakPembayaran")->name("cetak-tagihan-dibayar");
                        Route::resource("", \App\Http\Controllers\Admin\Keuangan\PenerimaanSiswa\DataPenerimaanController::class)->parameters(["" => "id"]);
                    });
                });

                Route::prefix("rekap-penerimaan")->name("rekap-penerimaan.")->group(function () {
                    Route::controller(\App\Http\Controllers\Admin\Keuangan\PenerimaanSiswa\RekapPenerimaanController::class)->group(function () {
                        Route::get("get-data", "getData")->name("get-data");
                        Route::get("get-column", "getColumn")->name("get-column");
                        Route::get("cetak-rekap", "cetakRekapPenerimaan")->name("cetak-rekap");
                        Route::get("cetak-tagihan-dibayar", "cetakPembayaran")->name("cetak-tagihan-dibayar");
                        Route::get("cetak-kartu-siswa", "cetakKartuSiswa")->name("cetak-kartu-siswa");
                        Route::get("cetak-per-nis", "cetakPerNis")->name("cetak-per-nis");
                        Route::resource("", \App\Http\Controllers\Admin\Keuangan\PenerimaanSiswa\RekapPenerimaanController::class)->parameters(["" => "id"]);
                    });
                });
            });

            Route::prefix("data-transfer-va")
                ->name("data-transfer-va.")
                ->controller(\App\Http\Controllers\Admin\Keuangan\Saldo\SccttranController::class)
                ->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::get("", "index")->name("index");
                });

            Route::prefix("saldo")->name("saldo.")->group(function () {
                $saldoVaRoutes = function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::get("get-saldo", "getSaldo")->name("get-saldo");
                    Route::get("export-transaksi", "exportTransaksi")->name("export-transaksi");
                    Route::get("{id}/export", "exportDetail")->name("export");
                    Route::prefix("data-transaksi")->name("data-transaksi.")->group(function () {
                        Route::get("", "transaksiIndex")->name("index");
                        Route::get("get-data", "getDataDataTransaksi")->name("get-data");
                        Route::get("get-column", "getColumnDataTransaksi")->name("get-column");
                    });
                    Route::post("tarik", "tarik")->name("tarik");
                    Route::prefix("transaksi")->name("transaksi.")->group(function () {
                        Route::get("get-data", "getDataTran")->name("get-data");
                        Route::get("get-column", "getColumnTran")->name("get-column");
                    });
                };

                Route::controller(\App\Http\Controllers\Admin\Keuangan\Saldo\SaldoVirtualAccountController::class)
                    ->prefix("saldo-va-open")->name("saldo-va-open.")->group($saldoVaRoutes);
                Route::resource("saldo-va-open", \App\Http\Controllers\Admin\Keuangan\Saldo\SaldoVirtualAccountController::class)
                    ->names("saldo-va-open");

                Route::controller(\App\Http\Controllers\Admin\Keuangan\Saldo\SaldoVirtualAccountController::class)
                    ->prefix("saldo-va-close")->name("saldo-va-close.")->group($saldoVaRoutes);
                Route::resource("saldo-va-close", \App\Http\Controllers\Admin\Keuangan\Saldo\SaldoVirtualAccountController::class)
                    ->names("saldo-va-close");

                // Legacy alias (manual pembayaran get-saldo + redirect halaman lama)
                Route::controller(\App\Http\Controllers\Admin\Keuangan\Saldo\SaldoVirtualAccountController::class)
                    ->prefix("saldo-virtual-account")->name("saldo-virtual-account.")->group(function () {
                        Route::get("get-saldo", "getSaldo")->name("get-saldo");
                        Route::get("", fn () => redirect()->route('admin.keuangan.saldo.saldo-va-close.index'));
                        Route::get("{any?}", fn () => redirect()->route('admin.keuangan.saldo.saldo-va-close.index'))
                            ->where('any', '.*');
                    });
            });

            Route::prefix("hapus-tagihan")->name("hapus-tagihan.")->group(function () {
                Route::controller(\App\Http\Controllers\Admin\Keuangan\HapusTagihanController::class)->group(function () {
                    Route::get("get-data", "getData")->name("get-data");
                    Route::get("get-column", "getColumn")->name("get-column");
                    Route::post("hapus-jamak", "bulkDestroy")->name("hapus-jamak");
                    Route::resource("", \App\Http\Controllers\Admin\Keuangan\HapusTagihanController::class)->parameters(["" => "id"]);
                });
            });
        });

        Route::prefix("manual-input")->name("manual-input.")->group(function () {
            Route::controller(\App\Http\Controllers\Admin\ManualInput\EditManualController::class)
                ->prefix("edit-manual")->name("edit-manual.")->group(function () {
                    Route::get("get-siswa", "getSiswa")->name("get-siswa");
                    Route::get("get-tagihan", "getTagihan")->name("get-tagihan");
                    Route::get("get-detail-taighan", "getDetailTagihan")->name("get-detail-tagihan");
                    Route::put("edit-tagihan", "editTagihan")->name("edit-tagihan");
                    Route::post("copy-tagihan", "copyTagihan")->name("copy-tagihan");
                    Route::resource("", \App\Http\Controllers\Admin\ManualInput\EditManualController::class)->parameters(["" => "id"]);
                });
        });
    });
