<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Attendance\AttendanceMasterController;
use App\Http\Controllers\Attendance\SheetController;
use App\Http\Controllers\Attendance\TripController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Comben\CombenMasterController;
use App\Http\Controllers\Comben\CutiController;
use App\Http\Controllers\Comben\CutiMasterController;
use App\Http\Controllers\Comben\DeklarasiController;
use App\Http\Controllers\Comben\MonitoringController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Demo\DemoAccountController;
use App\Http\Controllers\Demo\DemoRequestController;
use App\Http\Controllers\Demo\WorkspaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LampiranController;
use App\Http\Controllers\LowonganController;
use App\Http\Controllers\Manpower\KaryawanAnakController;
use App\Http\Controllers\Manpower\KaryawanController;
use App\Http\Controllers\Manpower\KaryawanExcelController;
use App\Http\Controllers\Manpower\KaryawanKeluarController;
use App\Http\Controllers\Manpower\KaryawanPenempatanController;
use App\Http\Controllers\Manpower\KaryawanPkwtController;
use App\Http\Controllers\Manpower\KaryawanProfileController;
use App\Http\Controllers\Manpower\MasterData\BodController;
use App\Http\Controllers\Manpower\MasterData\DepartemenController;
use App\Http\Controllers\Manpower\MasterData\DivisiController;
use App\Http\Controllers\Manpower\MasterData\JabatanController;
use App\Http\Controllers\Manpower\MasterData\JabatanExcelController;
use App\Http\Controllers\Manpower\MasterData\JenisKaryawanController;
use App\Http\Controllers\Manpower\MasterData\LokasiPenerimaanController;
use App\Http\Controllers\Manpower\MasterData\MasterDataController;
use App\Http\Controllers\Manpower\MasterData\MasterOptionController;
use App\Http\Controllers\Manpower\MasterData\SectionController;
use App\Http\Controllers\Manpower\MasterData\SubGroupController;
use App\Http\Controllers\Manpower\MasterData\SubLocationController;
use App\Http\Controllers\Manpower\MasterData\WorkLocationController;
use App\Http\Controllers\Manpower\MppController;
use App\Http\Controllers\Manpower\OrgPlanController;
use App\Http\Controllers\Manpower\OrgStructureController;
use App\Http\Controllers\Manpower\PengajuanUpdateDataController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\Portal\DataController as PortalDataController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Recruitment\ApplicantController;
use App\Http\Controllers\Recruitment\FormController;
use App\Http\Controllers\Recruitment\LaporanController;
use App\Http\Controllers\Recruitment\TrackingController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

// White label logos and favicon: also on the login page, so without signing in.
Route::get('/branding/{jenis}', [BrandingController::class, 'file'])->whereIn('jenis', ['logo', 'logo-full', 'favicon'])->name('branding.file');

// Lowongan: the open vacancies and their application forms, for anyone (no sign-in).
Route::controller(LowonganController::class)->prefix('lowongan')->name('lowongan')->group(function () {
    Route::get('/', 'index');
    Route::get('/{form}', 'show')->whereNumber('form')->name('.show');
    Route::post('/{form}', 'submit')->whereNumber('form')->middleware('throttle:lamaran')->name('.submit');
});

// Eloas: the landing page for visitors ("/" leads signed-in accounts on, see HomeController),
// and Pengajuan Akun Demo with the page that follows its workspace being prepared.
Route::get('/', HomeController::class)->name('home');
Route::controller(DemoRequestController::class)->prefix('demo')->name('demo')->group(function () {
    Route::get('/', 'create');
    Route::post('/', 'store')->middleware('throttle:demo')->name('.store');
    Route::get('/status/{kode}', 'status')->where('kode', '[a-z0-9]{6}')->name('.status');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

/*
 * Access (Settings → Akun & Akses): "menu:<key>" lets an account read a menu's pages with
 * "lihat" and change its data with "ubah"; menus listing employees only show the
 * account's locations. The Super Admin has every menu.
 */
Route::middleware(['auth', 'account.ready'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Dashboard: an overview of every module for the account's locations.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Inside a demo workspace: switch its data (demo data again, or empty for one's own).
    Route::post('/workspace/data', [WorkspaceController::class, 'data'])->middleware('can:manage-users')->name('workspace.data');

    // Own password; a first-time password must be changed before anything else.
    Route::get('/akun/password', [PasswordController::class, 'edit'])->name('account.password');
    Route::put('/akun/password', [PasswordController::class, 'update'])->name('account.password.update');

    // Modul Recruitment: Tracking (stages and candidates), Pelamar, Pengaturan Form and Laporan.
    Route::middleware('menu:recruitment')->prefix('recruitment')->name('recruitment.')->group(function () {
        Route::controller(TrackingController::class)->group(function () {
            Route::get('/tracking', 'index')->name('tracking');
            Route::post('/tahap', 'storeStage')->name('tahap.store');
            Route::put('/tahap/{stage}', 'updateStage')->whereNumber('stage')->name('tahap.update');
            Route::post('/tahap/{stage}/geser', 'moveStage')->whereNumber('stage')->name('tahap.move');
            Route::delete('/tahap/{stage}', 'destroyStage')->whereNumber('stage')->name('tahap.destroy');
            Route::post('/kandidat/pindah', 'move')->name('kandidat.move');
            Route::post('/kandidat', 'store')->name('kandidat.store');
            Route::get('/kandidat/{candidate}', 'show')->whereNumber('candidate')->name('kandidat.show');
            // Multipart (a CV): POST with _method=put.
            Route::put('/kandidat/{candidate}', 'update')->whereNumber('candidate')->name('kandidat.update');
            Route::delete('/kandidat/{candidate}', 'destroy')->whereNumber('candidate')->name('kandidat.destroy');
            Route::get('/kandidat/{candidate}/cv', 'cv')->whereNumber('candidate')->name('kandidat.cv');
            Route::post('/kandidat/{candidate}/catatan', 'storeNote')->whereNumber('candidate')->name('kandidat.note.store');
            Route::delete('/catatan/{note}', 'destroyNote')->whereNumber('note')->name('kandidat.note.destroy');
            Route::post('/kandidat/{candidate}/tolak', 'reject')->whereNumber('candidate')->name('kandidat.reject');
            Route::post('/kandidat/{candidate}/karyawan', 'convert')->whereNumber('candidate')->name('kandidat.convert');
        });
        Route::controller(ApplicantController::class)->prefix('pelamar')->name('pelamar')->group(function () {
            Route::get('/', 'index');
            Route::get('/{applicant}', 'show')->whereNumber('applicant')->name('.show');
            Route::get('/{applicant}/file/{field}', 'file')->whereNumber('applicant')->name('.file');
            Route::post('/{applicant}/tracking', 'moveToTracking')->whereNumber('applicant')->name('.tracking');
            Route::post('/{applicant}/tolak', 'reject')->whereNumber('applicant')->name('.reject');
            Route::delete('/{applicant}', 'destroy')->whereNumber('applicant')->name('.destroy');
        });
        Route::controller(FormController::class)->prefix('form')->name('form')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store')->name('.store');
            Route::get('/{form}', 'edit')->whereNumber('form')->name('.edit');
            Route::put('/{form}', 'update')->whereNumber('form')->name('.update');
            Route::post('/{form}/buka-tutup', 'toggle')->whereNumber('form')->name('.toggle');
            Route::post('/{form}/salin', 'duplicate')->whereNumber('form')->name('.duplicate');
            Route::delete('/{form}', 'destroy')->whereNumber('form')->name('.destroy');
        });
        Route::get('/laporan', [LaporanController::class, 'index'])->name('laporan');
        Route::get('/laporan/export', [LaporanController::class, 'export'])->name('laporan.export');
    });

    Route::prefix('manpower')->name('manpower.')->group(function () {
        // Struktur Organisasi: the plan (positions, lines) and the chart with people.
        Route::middleware('menu:org-structure')->group(function () {
            Route::get('/org-structure', [OrgStructureController::class, 'index'])->name('org-structure');
            Route::get('/org-structure/plan', [OrgPlanController::class, 'show'])->name('org-plan');
            Route::get('/org-structure/positions', [OrgStructureController::class, 'positions'])->name('org-structure.positions');
            Route::prefix('org-structure/plan/{workLocation}')->name('org-plan.')->group(function () {
                Route::post('/setup', [OrgPlanController::class, 'setup'])->name('setup');
                Route::post('/ops', [OrgPlanController::class, 'ops'])->name('ops');
                Route::post('/link', [OrgPlanController::class, 'link'])->name('link');
            });
        });

        // Master Data Personalia: NRP codes, organisation and choice lists.
        Route::middleware('menu:master-personalia')->prefix('master-data')->name('master.')->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('index');

            $crud = ['store', 'update', 'destroy'];
            Route::resource('jenis-karyawan', JenisKaryawanController::class)->only($crud)->parameters(['jenis-karyawan' => 'jenisKaryawan']);
            Route::resource('lokasi-penerimaan', LokasiPenerimaanController::class)->only($crud)->parameters(['lokasi-penerimaan' => 'lokasiPenerimaan']);
            Route::resource('work-location', WorkLocationController::class)->only($crud)->parameters(['work-location' => 'workLocation']);
            Route::resource('divisi', DivisiController::class)->only($crud);
            // Named explicitly: Laravel's English singularizer would turn it into {departeman}.
            Route::resource('departemen', DepartemenController::class)->only($crud)->parameters(['departemen' => 'departemen']);
            Route::get('/jabatan/excel/template', [JabatanExcelController::class, 'template'])->name('jabatan.excel.template');
            Route::post('/jabatan/excel/preview', [JabatanExcelController::class, 'preview'])->name('jabatan.excel.preview');
            Route::post('/jabatan/excel/import', [JabatanExcelController::class, 'import'])->name('jabatan.excel.import');
            Route::post('/jabatan/excel/perbaikan', [JabatanExcelController::class, 'perbaikan'])->name('jabatan.excel.perbaikan');
            // A position's name is composed from its placement, so it is created or removed, never edited.
            Route::resource('jabatan', JabatanController::class)->only(['store', 'destroy']);
            Route::resource('bod', BodController::class)->only($crud);
            Route::resource('section', SectionController::class)->only($crud);
            Route::resource('sub-lokasi', SubLocationController::class)->only($crud)->parameters(['sub-lokasi' => 'subLocation']);
            Route::resource('sub-group', SubGroupController::class)->only($crud)->parameters(['sub-group' => 'subGroup']);
        });

        // Choice lists: Master Data Personalia, and Status Pengajuan Uang in Master Data Attendance.
        Route::middleware('menu:master-personalia,master-attendance')->prefix('master-data/opsi')->name('master.opsi.')->group(function () {
            Route::post('/{kategori}', [MasterOptionController::class, 'store'])->name('store');
            Route::post('/{kategori}/banyak', [MasterOptionController::class, 'storeMany'])->name('store-many');
            Route::put('/{option}', [MasterOptionController::class, 'update'])->whereNumber('option')->name('update');
            Route::delete('/{option}', [MasterOptionController::class, 'destroy'])->whereNumber('option')->name('destroy');
            Route::post('/{option}/geser', [MasterOptionController::class, 'move'])->whereNumber('option')->name('move');
        });

        // Man Power Planning: the plan per work location and period, its revisions, Excel and vacancies.
        Route::controller(MppController::class)->prefix('mpp')->name('mpp')->group(function () {
            Route::middleware('menu:mpp')->group(function () {
                Route::get('/', 'index');
                Route::put('/{plan}', 'save')->whereNumber('plan')->name('.save');
                Route::get('/{plan}/export', 'export')->whereNumber('plan')->name('.export');
                Route::post('/{plan}/import', 'import')->whereNumber('plan')->name('.import');
                Route::post('/{plan}/lowongan/{jabatan}', 'lowongan')->whereNumber(['plan', 'jabatan'])->name('.lowongan');
                Route::post('/revisi/{revisi}/batal', 'cancel')->whereNumber('revisi')->name('.revisi.cancel');
            });
            Route::post('/revisi/{revisi}/verifikasi', 'verify')->whereNumber('revisi')->middleware('menu:mpp-approve')->name('.revisi.verify');
        });
        Route::put('/master-data/mpp', [MppController::class, 'settings'])->middleware('menu:master-personalia')->name('master.mpp');

        // Pengajuan Update Data from the employee portal, approved or rejected by HR.
        Route::middleware('menu:update-data')->prefix('pengajuan-data')->name('pengajuan-data')->controller(PengajuanUpdateDataController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/{pengajuan}/verifikasi', 'verify')->whereNumber('pengajuan')->name('.verify');
        });

        // Every Man Power view and action carries personal data (NIK, bank, family, exits).
        Route::middleware('menu:karyawan')->prefix('karyawan')->name('karyawan.')->group(function () {
            Route::controller(KaryawanController::class)->group(function () {
                Route::get('/', 'dashboard')->name('dashboard');
                Route::get('/data-lengkap', 'dataLengkap')->name('data-lengkap');
                Route::get('/keluar', 'keluar')->name('keluar');
                Route::post('/', 'store')->name('store');
                Route::delete('/{karyawan}', 'destroy')->whereNumber('karyawan')->name('destroy');
            });

            Route::controller(KaryawanExcelController::class)->prefix('excel')->name('excel.')->group(function () {
                Route::get('/template', 'template')->name('template');
                Route::get('/export', 'export')->name('export');
                Route::get('/template-kelompok', 'groupTemplate')->name('group-template');
                Route::post('/import', 'import')->name('import');
            });

            Route::prefix('{karyawan}')->whereNumber('karyawan')->group(function () {
                Route::get('/profil', [KaryawanProfileController::class, 'show'])->name('profil');
                Route::put('/profil', [KaryawanProfileController::class, 'update'])->name('profil.update');

                Route::post('/keluar', [KaryawanKeluarController::class, 'store'])->name('keluar.store');
                Route::delete('/keluar', [KaryawanKeluarController::class, 'destroy'])->name('keluar.destroy');

                // Placement: work location + SubGroup + unit (Ubah Penempatan), or straight into a position (bagan "Kelola").
                Route::put('/penempatan', [KaryawanPenempatanController::class, 'update'])->name('penempatan.update');
                Route::put('/jabatan', [KaryawanPenempatanController::class, 'assign'])->name('jabatan.assign');
                Route::post('/sub-lokasi', [KaryawanPenempatanController::class, 'subLokasi'])->name('sub-lokasi');
                Route::post('/copot', [KaryawanPenempatanController::class, 'copot'])->name('copot');

                Route::post('/pkwt', [KaryawanPkwtController::class, 'store'])->name('pkwt.store');
                Route::put('/pkwt/{pkwt}', [KaryawanPkwtController::class, 'update'])->name('pkwt.update');
                Route::delete('/pkwt/{pkwt}', [KaryawanPkwtController::class, 'destroy'])->name('pkwt.destroy');

                Route::post('/anak', [KaryawanAnakController::class, 'store'])->name('anak.store');
                Route::put('/anak/{anak}', [KaryawanAnakController::class, 'update'])->name('anak.update');
                Route::delete('/anak/{anak}', [KaryawanAnakController::class, 'destroy'])->name('anak.destroy');
            });
        });
    });

    // Attendance: Roster (plan), Absensi (actual), Trip & Leave and its Pengajuan Dinas list.
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/roster', [SheetController::class, 'show'])->defaults('type', 'roster')->middleware('menu:roster')->name('roster');
        Route::get('/absensi', [SheetController::class, 'show'])->defaults('type', 'absensi')->middleware('menu:absensi')->name('absensi');
        Route::controller(SheetController::class)->prefix('{type}')->whereIn('type', ['roster', 'absensi'])->middleware('menu:@type')->name('sheet.')->group(function () {
            Route::post('/simpan', 'save')->name('save');
            Route::post('/import', 'import')->name('import');
            Route::post('/import/simpan', 'importSave')->name('import.save');
            Route::get('/export', 'export')->name('export');
        });

        Route::controller(TripController::class)->group(function () {
            Route::middleware('menu:trip-leave')->group(function () {
                Route::get('/trip-leave', 'index')->name('trip');
                Route::post('/trip-leave', 'store')->name('trip.store');
            });
            Route::middleware('menu:trip-leave,pengajuan-dinas')->group(function () {
                Route::put('/trip-leave/{trip}', 'update')->name('trip.update');
                Route::delete('/trip-leave/{trip}', 'destroy')->name('trip.destroy');
                Route::get('/trip-leave/export', 'export')->name('trip.export');
                // The cuti form: balance, field-leave allowance and Cuti Besar of the employee.
                Route::get('/cuti-info/{karyawan}', [CutiController::class, 'info'])->whereNumber('karyawan')->name('cuti-info');
            });
            Route::middleware('menu:pengajuan-dinas')->group(function () {
                Route::put('/trip-leave/{trip}/status-uang', 'statusUang')->name('trip.status-uang');
                Route::put('/trip-leave/{trip}/tiket', 'tiket')->name('trip.tiket');
                Route::get('/pengajuan-dinas/template', 'template')->name('pengajuan.template');
                Route::post('/pengajuan-dinas/import', 'import')->name('pengajuan.import');
                Route::post('/trip-leave/{trip}/verifikasi', 'verify')->name('trip.verify');
                Route::get('/pengajuan-dinas', 'list')->name('pengajuan');
            });
        });

        Route::controller(AttendanceMasterController::class)->middleware('menu:master-attendance')->prefix('master-data')->name('master.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/kode-shift', 'storeCode')->name('kode-shift.store');
            Route::put('/kode-shift/{shiftCode}', 'updateCode')->name('kode-shift.update');
            Route::delete('/kode-shift/{shiftCode}', 'destroyCode')->name('kode-shift.destroy');
            Route::post('/pola-roster', 'storePattern')->name('pola-roster.store');
            Route::put('/pola-roster/{rosterPattern}', 'updatePattern')->name('pola-roster.update');
            Route::delete('/pola-roster/{rosterPattern}', 'destroyPattern')->name('pola-roster.destroy');
            Route::post('/hari-libur', 'storeHoliday')->name('hari-libur.store');
            Route::put('/hari-libur/{holiday}', 'updateHoliday')->name('hari-libur.update');
            Route::delete('/hari-libur/{holiday}', 'destroyHoliday')->name('hari-libur.destroy');
        });
    });

    // Comben: Deklarasi Pengobatan / Kacamata against the plafon, its monitoring and master data.
    Route::prefix('comben')->name('comben.')->group(function () {
        Route::controller(DeklarasiController::class)->middleware('menu:deklarasi')->group(function () {
            Route::get('/deklarasi', 'index')->name('deklarasi');
            Route::get('/deklarasi/export', 'export')->name('deklarasi.export');
            Route::post('/deklarasi', 'store')->name('deklarasi.store');
            Route::put('/deklarasi/{deklarasi}', 'update')->name('deklarasi.update');
            Route::delete('/deklarasi/{deklarasi}', 'destroy')->name('deklarasi.destroy');
            Route::post('/deklarasi/{deklarasi}/verifikasi', 'verify')->name('deklarasi.verify');
            Route::get('/karyawan/{karyawan}/plafon', 'info')->whereNumber('karyawan')->name('karyawan.plafon');
        });
        Route::get('/monitoring', [MonitoringController::class, 'index'])->middleware('menu:monitoring-plafon')->name('monitoring');
        Route::get('/monitoring/export', [MonitoringController::class, 'export'])->middleware('menu:monitoring-plafon')->name('monitoring.export');

        // Cuti Tahunan & Cuti Besar.
        Route::controller(CutiController::class)->middleware('menu:cuti')->prefix('cuti')->name('cuti')->group(function () {
            Route::get('/', 'index');
            Route::get('/export', 'export')->name('.export');
            Route::get('/karyawan/{karyawan}', 'show')->whereNumber('karyawan')->name('.show');
            Route::post('/karyawan/{karyawan}/pengambilan', 'storePengambilan')->whereNumber('karyawan')->name('.pengambilan.store');
            Route::delete('/pengambilan/{pengambilan}', 'destroyPengambilan')->whereNumber('pengambilan')->name('.pengambilan.destroy');
            Route::post('/karyawan/{karyawan}/besar', 'decideBesar')->whereNumber('karyawan')->name('.besar.decide');
            Route::post('/besar/{cutiBesar}/verifikasi', 'verifyBesar')->whereNumber('cutiBesar')->name('.besar.verify');
        });

        Route::controller(CombenMasterController::class)->middleware('menu:master-comben')->prefix('master-data')->name('master.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/golongan', 'storeGolongan')->name('golongan.store');
            Route::put('/golongan/{golongan}', 'updateGolongan')->name('golongan.update');
            Route::delete('/golongan/{golongan}', 'destroyGolongan')->name('golongan.destroy');
            Route::post('/skala-upah', 'storeSkala')->name('skala.store');
            Route::put('/skala-upah/{skalaUpah}', 'updateSkala')->name('skala.update');
            Route::delete('/skala-upah/{skalaUpah}', 'destroySkala')->name('skala.destroy');
            Route::post('/skala-kolom', 'storeKolom')->name('kolom.store');
            Route::delete('/skala-kolom/{kolom}', 'destroyKolom')->name('kolom.destroy');
            Route::post('/plafon/{jenis}', 'storePlafon')->whereIn('jenis', ['pengobatan', 'kacamata'])->name('plafon.store');
            Route::put('/plafon/{jenis}/{id}', 'updatePlafon')->whereIn('jenis', ['pengobatan', 'kacamata'])->whereNumber('id')->name('plafon.update');
            Route::delete('/plafon/{jenis}/{id}', 'destroyPlafon')->whereIn('jenis', ['pengobatan', 'kacamata'])->whereNumber('id')->name('plafon.destroy');
            Route::put('/cuti-aturan', [CutiMasterController::class, 'updateAturan'])->name('cuti-aturan.update');
            Route::post('/cuti-lapangan', [CutiMasterController::class, 'storeLapangan'])->name('cuti-lapangan.store');
            Route::put('/cuti-lapangan/{lapangan}', [CutiMasterController::class, 'updateLapangan'])->whereNumber('lapangan')->name('cuti-lapangan.update');
            Route::delete('/cuti-lapangan/{lapangan}', [CutiMasterController::class, 'destroyLapangan'])->whereNumber('lapangan')->name('cuti-lapangan.destroy');
            Route::put('/lumpsum-cuti', [CutiMasterController::class, 'updateLumpsum'])->name('lumpsum-cuti.update');
        });
    });

    // Settings → Akun & Akses: Super Admin only.
    Route::middleware('can:manage-users')->prefix('settings')->name('settings.')->controller(UserController::class)->group(function () {
        Route::get('/akun', 'index')->name('users');
        Route::post('/akun', 'store')->name('users.store');
        Route::put('/akun/{user}', 'update')->whereNumber('user')->name('users.update');
        Route::delete('/akun/{user}', 'destroy')->whereNumber('user')->name('users.destroy');
        Route::post('/akun-karyawan', 'storeKaryawan')->name('karyawan-accounts.store');
        Route::post('/akun-karyawan/reset-masal', 'resetKaryawanMany')->name('karyawan-accounts.reset-many');
        Route::post('/akun-karyawan/{user}/reset', 'resetKaryawan')->whereNumber('user')->name('karyawan-accounts.reset');
        Route::post('/akun-karyawan/{user}/status', 'toggleKaryawan')->whereNumber('user')->name('karyawan-accounts.toggle');
        Route::delete('/akun-karyawan/{user}', 'destroyKaryawan')->whereNumber('user')->name('karyawan-accounts.destroy');
    });
    // Settings → Akun Demo: the demo requests and their workspaces (main application, Super Admin).
    Route::middleware(['can:manage-users', 'central'])->prefix('settings/akun-demo')->name('settings.demo')->controller(DemoAccountController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/{account}/setujui', 'approve')->whereNumber('account')->name('.approve');
        Route::post('/{account}/perpanjang', 'extend')->whereNumber('account')->name('.extend');
        Route::post('/{account}/status', 'toggle')->whereNumber('account')->name('.toggle');
        Route::post('/{account}/reset', 'reset')->whereNumber('account')->name('.reset');
        Route::delete('/{account}', 'destroy')->whereNumber('account')->name('.destroy');
    });
    // Settings → White Label: Super Admin only.
    Route::middleware('can:manage-users')->prefix('settings')->name('settings.')->controller(BrandingController::class)->group(function () {
        Route::get('/white-label', 'edit')->name('branding');
        Route::post('/white-label', 'update')->name('branding.update');
    });

    // The bell: pengajuan from the portal waiting for this account.
    Route::controller(NotifikasiController::class)->prefix('notifikasi')->name('notifikasi')->group(function () {
        Route::get('/', 'index');
        Route::get('/jumlah', 'count')->name('.count');
        Route::post('/baca-semua', 'readAll')->name('.read-all');
        Route::post('/{id}/baca', 'read')->whereUuid('id')->name('.read');
    });

    // A nota / signed form, for whoever may see its deklarasi or cuti / dinas.
    Route::get('/lampiran/{lampiran}', LampiranController::class)->middleware('scoped')->whereNumber('lampiran')->name('lampiran.show');

    // Portal Karyawan: an employee's own plafon and pengajuan (deklarasi, cuti, dinas).
    Route::middleware(['can:portal', 'scoped'])->prefix('portal')->name('portal')->controller(PortalController::class)->group(function () {
        Route::get('/', 'home');
        Route::get('/plafon', 'plafon')->name('.plafon');
        Route::post('/deklarasi', 'storeDeklarasi')->name('.deklarasi');
        Route::post('/cuti-dinas', 'storeTrip')->name('.trip');
        Route::delete('/pengajuan/{jenis}/{id}', 'cancel')->whereIn('jenis', ['deklarasi', 'trip'])->whereNumber('id')->name('.cancel');
        // Data Saya: changes to the own data, sent to HR.
        Route::get('/data', [PortalDataController::class, 'show'])->name('.data');
        Route::post('/data', [PortalDataController::class, 'store'])->name('.data.store');
        Route::delete('/data/{id}', [PortalDataController::class, 'cancel'])->whereNumber('id')->name('.data.cancel');
        // Own leave balance for the cuti form, and the Cuti Besar choice (checked by HR).
        Route::get('/cuti-info', 'cutiInfo')->name('.cuti-info');
        Route::post('/cuti-besar', 'chooseBesar')->name('.cuti-besar');
    });
});
