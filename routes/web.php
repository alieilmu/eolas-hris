<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\LicenseController;
use App\Http\Controllers\Time\TimeSettingsController;
use App\Http\Controllers\Time\TimeClockController;
use App\Http\Controllers\Time\SheetController;
use App\Http\Controllers\Time\LeaveTravelController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Compensation\CompensationSettingsController;
use App\Http\Controllers\Time\LeaveBalanceController;
use App\Http\Controllers\Compensation\LeaveSettingsController;
use App\Http\Controllers\Compensation\BenefitClaimController;
use App\Http\Controllers\Compensation\PayrollSimulationController;
use App\Http\Controllers\Compensation\PayrollRunController;
use App\Http\Controllers\Compensation\PayrollInputController;
use App\Http\Controllers\Compensation\LoanController;
use App\Http\Controllers\Compensation\PayrollComponentController;
use App\Http\Controllers\Compensation\SalaryController;
use App\Http\Controllers\Compensation\BenefitBalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Trial\TrialAccountController;
use App\Http\Controllers\Trial\TrialRequestController;
use App\Http\Controllers\Trial\WorkspaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CareersController;
use App\Http\Controllers\CoreHr\EmployeeChildController;
use App\Http\Controllers\CoreHr\EmployeeController;
use App\Http\Controllers\CoreHr\EmployeeExcelController;
use App\Http\Controllers\CoreHr\EmployeeTerminationController;
use App\Http\Controllers\CoreHr\EmployeePlacementController;
use App\Http\Controllers\CoreHr\EmployeeContractController;
use App\Http\Controllers\CoreHr\EmployeeProfileController;
use App\Http\Controllers\CoreHr\Settings\DirectorateController;
use App\Http\Controllers\CoreHr\Settings\DepartmentController;
use App\Http\Controllers\CoreHr\Settings\DivisionController;
use App\Http\Controllers\CoreHr\Settings\PositionController;
use App\Http\Controllers\CoreHr\Settings\PositionExcelController;
use App\Http\Controllers\CoreHr\Settings\EmployeeCategoryController;
use App\Http\Controllers\CoreHr\Settings\EmployeeNumberFormatController;
use App\Http\Controllers\CoreHr\Settings\PointOfHireController;
use App\Http\Controllers\CoreHr\Settings\SettingsController;
use App\Http\Controllers\CoreHr\Settings\LookupValueController;
use App\Http\Controllers\CoreHr\Settings\SectionController;
use App\Http\Controllers\CoreHr\Settings\JobLevelController;
use App\Http\Controllers\CoreHr\Settings\SubLocationController;
use App\Http\Controllers\CoreHr\Settings\WorkLocationController;
use App\Http\Controllers\CoreHr\WorkforcePlanController;
use App\Http\Controllers\CoreHr\OrgPlanController;
use App\Http\Controllers\CoreHr\OrgChartController;
use App\Http\Controllers\CoreHr\ChangeRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SelfService\ProfileController as PortalDataController;
use App\Http\Controllers\SelfService\SelfServiceController;
use App\Http\Controllers\SelfService\PayslipController as PortalPayslipController;
use App\Http\Controllers\SelfService\LoanController as PortalLoanController;
use App\Http\Controllers\Recruitment\ApplicantController;
use App\Http\Controllers\Recruitment\JobPostingController;
use App\Http\Controllers\Recruitment\ReportController;
use App\Http\Controllers\Recruitment\PipelineController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\SetupController;
use App\Http\Controllers\Settings\UserController;
use App\Support\LegacyUrls;
use Illuminate\Support\Facades\Route;

// White label logos and favicon: also on the login page, so without signing in.
Route::get('/branding/{type}', [BrandingController::class, 'file'])->whereIn('type', ['logo', 'logo-full', 'favicon'])->name('branding.file');

// Lowongan: the open vacancies and their application forms, for anyone (no sign-in).
Route::controller(CareersController::class)->prefix('careers')->name('careers')->group(function () {
    Route::get('/', 'index');
    Route::get('/{form}', 'show')->whereNumber('form')->name('.show');
    Route::post('/{form}', 'submit')->whereNumber('form')->middleware('throttle:application')->name('.submit');
});

// Eolas: the landing page for visitors ("/" leads signed-in accounts on, see HomeController),
// and Pengajuan Akun Demo with the page that follows its workspace being prepared.
Route::get('/', HomeController::class)->name('home');
Route::controller(TrialRequestController::class)->prefix('demo')->name('demo')->middleware('saas')->group(function () {
    Route::get('/', 'create');
    Route::post('/', 'store')->middleware('throttle:demo')->name('.store');
    Route::get('/status/{code}', 'status')->where('code', '[a-z0-9]{6}')->name('.status');
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
Route::middleware(['auth', 'two-factor', 'account.ready', 'licensed'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Dashboard: an overview of every module for the account's locations.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Inside a demo workspace: switch its data (demo data again, or empty for one's own).
    Route::post('/workspace/data', [WorkspaceController::class, 'data'])->middleware(['saas', 'can:manage-users'])->name('workspace.data');

    // Own password; a first-time password must be changed before anything else.
    Route::get('/account/password', [PasswordController::class, 'edit'])->name('account.password');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');

    // Verifikasi 2 langkah (authenticator app): own setup, the code after the password.
    Route::controller(TwoFactorController::class)->group(function () {
        Route::get('/two-factor/challenge', 'challenge')->name('two-factor.challenge');
        Route::post('/two-factor/challenge', 'verify')->middleware('throttle:6,1')->name('two-factor.challenge.verify');
        Route::get('/account/two-factor', 'show')->name('account.two-factor');
        Route::post('/account/two-factor', 'start')->name('account.two-factor.start');
        Route::post('/account/two-factor/confirm', 'confirm')->middleware('throttle:10,1')->name('account.two-factor.confirm');
        Route::post('/account/two-factor/recovery-codes', 'recoveryCodes')->name('account.two-factor.recovery');
        Route::post('/account/two-factor/skip', 'skip')->name('account.two-factor.skip');
        Route::delete('/account/two-factor', 'destroy')->name('account.two-factor.destroy');
    });

    // Modul Recruitment: Tracking (stages and candidates), Pelamar, Pengaturan Form and Laporan.
    Route::middleware('menu:recruitment')->prefix('recruitment')->name('recruitment.')->group(function () {
        Route::controller(PipelineController::class)->group(function () {
            Route::get('/pipeline', 'index')->name('tracking');
            Route::post('/stage', 'storeStage')->name('stage.store');
            Route::put('/stage/{stage}', 'updateStage')->whereNumber('stage')->name('stage.update');
            Route::post('/stage/{stage}/move', 'moveStage')->whereNumber('stage')->name('stage.move');
            Route::delete('/stage/{stage}', 'destroyStage')->whereNumber('stage')->name('stage.destroy');
            Route::post('/candidate/move', 'move')->name('candidate.move');
            Route::post('/candidate', 'store')->name('candidate.store');
            Route::get('/candidate/{candidate}', 'show')->whereNumber('candidate')->name('candidate.show');
            // Multipart (a CV): POST with _method=put.
            Route::put('/candidate/{candidate}', 'update')->whereNumber('candidate')->name('candidate.update');
            Route::delete('/candidate/{candidate}', 'destroy')->whereNumber('candidate')->name('candidate.destroy');
            Route::get('/candidate/{candidate}/cv', 'cv')->whereNumber('candidate')->name('candidate.cv');
            Route::post('/candidate/{candidate}/notes', 'storeNote')->whereNumber('candidate')->name('candidate.note.store');
            Route::delete('/notes/{note}', 'destroyNote')->whereNumber('note')->name('candidate.note.destroy');
            Route::post('/candidate/{candidate}/reject', 'reject')->whereNumber('candidate')->name('candidate.reject');
            Route::post('/candidate/{candidate}/employee', 'convert')->whereNumber('candidate')->name('candidate.convert');
        });
        Route::controller(ApplicantController::class)->prefix('applicants')->name('applicants')->group(function () {
            Route::get('/', 'index');
            Route::get('/{applicant}', 'show')->whereNumber('applicant')->name('.show');
            Route::get('/{applicant}/file/{field}', 'file')->whereNumber('applicant')->name('.file');
            Route::post('/{applicant}/tracking', 'moveToTracking')->whereNumber('applicant')->name('.tracking');
            Route::post('/{applicant}/reject', 'reject')->whereNumber('applicant')->name('.reject');
            Route::delete('/{applicant}', 'destroy')->whereNumber('applicant')->name('.destroy');
        });
        Route::controller(JobPostingController::class)->prefix('job-postings')->name('form')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store')->name('.store');
            Route::get('/{form}', 'edit')->whereNumber('form')->name('.edit');
            Route::put('/{form}', 'update')->whereNumber('form')->name('.update');
            Route::post('/{form}/toggle', 'toggle')->whereNumber('form')->name('.toggle');
            Route::post('/{form}/duplicate', 'duplicate')->whereNumber('form')->name('.duplicate');
            Route::delete('/{form}', 'destroy')->whereNumber('form')->name('.destroy');
        });
        Route::get('/reports', [ReportController::class, 'index'])->name('report');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('report.export');
    });

    Route::prefix('core-hr')->name('core_hr.')->group(function () {
        // Struktur Organisasi: the plan (positions, lines) and the chart with people.
        Route::middleware('menu:org-chart')->group(function () {
            Route::get('/org-chart', [OrgChartController::class, 'index'])->name('org-chart');
            Route::get('/org-chart/plan', [OrgPlanController::class, 'show'])->name('org-plan');
            Route::get('/org-chart/positions', [OrgChartController::class, 'positions'])->name('org-chart.positions');
            Route::prefix('org-chart/plan/{workLocation}')->name('org-plan.')->group(function () {
                Route::post('/setup', [OrgPlanController::class, 'setup'])->name('setup');
                Route::post('/ops', [OrgPlanController::class, 'ops'])->name('ops');
                Route::post('/link', [OrgPlanController::class, 'link'])->name('link');
            });
        });

        // Master Data Personalia: NRP codes, organisation and choice lists.
        Route::middleware('menu:core-hr-settings')->prefix('settings')->name('master.')->group(function () {
            Route::get('/', [SettingsController::class, 'index'])->name('index');

            $crud = ['store', 'update', 'destroy'];
            Route::put('/employee-number-format', [EmployeeNumberFormatController::class, 'update'])->name('employee-number-format.update');
            Route::resource('employee-categories', EmployeeCategoryController::class)->only($crud)->parameters(['employee-categories' => 'jenisKaryawan']);
            Route::resource('points-of-hire', PointOfHireController::class)->only($crud)->parameters(['points-of-hire' => 'lokasiPenerimaan']);
            Route::resource('work-locations', WorkLocationController::class)->only($crud)->parameters(['work-locations' => 'workLocation']);
            Route::resource('division', DivisionController::class)->only($crud);
            // Named explicitly: Laravel's English singularizer would turn it into {departeman}.
            Route::resource('department', DepartmentController::class)->only($crud)->parameters(['department' => 'department']);
            Route::get('/position/excel/template', [PositionExcelController::class, 'template'])->name('position.excel.template');
            Route::post('/position/excel/preview', [PositionExcelController::class, 'preview'])->name('position.excel.preview');
            Route::post('/position/excel/import', [PositionExcelController::class, 'import'])->name('position.excel.import');
            Route::post('/position/excel/fix', [PositionExcelController::class, 'fix'])->name('position.excel.fix');
            // A position's name is composed from its placement, so it is created or removed, never edited.
            Route::resource('position', PositionController::class)->only(['store', 'destroy']);
            Route::resource('directorate', DirectorateController::class)->only($crud);
            Route::resource('section', SectionController::class)->only($crud);
            Route::resource('sub-locations', SubLocationController::class)->only($crud)->parameters(['sub-locations' => 'subLocation']);
            Route::resource('job-levels', JobLevelController::class)->only($crud)->parameters(['job-levels' => 'jobLevel']);
        });

        // Choice lists: Master Data Personalia, and Status Pengajuan Uang in Master Data Attendance.
        Route::middleware('menu:core-hr-settings,time-settings')->prefix('settings/options')->name('master.options.')->group(function () {
            Route::post('/{category}', [LookupValueController::class, 'store'])->name('store');
            Route::post('/{category}/bulk', [LookupValueController::class, 'storeMany'])->name('store-many');
            Route::put('/{option}', [LookupValueController::class, 'update'])->whereNumber('option')->name('update');
            Route::delete('/{option}', [LookupValueController::class, 'destroy'])->whereNumber('option')->name('destroy');
            Route::post('/{option}/move', [LookupValueController::class, 'move'])->whereNumber('option')->name('move');
        });

        // Man Power Planning: the plan per work location and period, its revisions, Excel and vacancies.
        Route::controller(WorkforcePlanController::class)->prefix('workforce-planning')->name('workforce_planning')->group(function () {
            Route::middleware('menu:workforce-planning')->group(function () {
                Route::get('/', 'index');
                Route::put('/{plan}', 'save')->whereNumber('plan')->name('.save');
                Route::get('/{plan}/export', 'export')->whereNumber('plan')->name('.export');
                Route::post('/{plan}/import', 'import')->whereNumber('plan')->name('.import');
                Route::post('/{plan}/careers/{position}', 'careers')->whereNumber(['plan', 'position'])->name('.careers');
                Route::post('/revision/{revision}/cancel', 'cancel')->whereNumber('revision')->name('.revision.cancel');
            });
            Route::post('/revision/{revision}/verification', 'verify')->whereNumber('revision')->middleware('menu:workforce-planning-approval')->name('.revision.verify');
        });
        Route::put('/settings/workforce-planning', [WorkforcePlanController::class, 'settings'])->middleware('menu:core-hr-settings')->name('master.workforce_planning');

        // Pengajuan Update Data from the employee portal, approved or rejected by HR.
        Route::middleware('menu:data-change-requests')->prefix('change-requests')->name('change-requests')->controller(ChangeRequestController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/{submission}/verification', 'verify')->whereNumber('submission')->name('.verify');
        });

        // Every Man Power view and action carries personal data (NIK, bank, family, exits).
        Route::middleware('menu:employees')->prefix('employees')->name('employee.')->group(function () {
            Route::controller(EmployeeController::class)->group(function () {
                Route::get('/', 'dashboard')->name('dashboard');
                Route::get('/full-data', 'fullData')->name('full-data');
                Route::get('/termination', 'termination')->name('termination');
                Route::post('/', 'store')->name('store');
                Route::delete('/{employee}', 'destroy')->whereNumber('employee')->name('destroy');
            });

            Route::controller(EmployeeExcelController::class)->prefix('excel')->name('excel.')->group(function () {
                Route::get('/template', 'template')->name('template');
                Route::get('/export', 'export')->name('export');
                Route::get('/group-template', 'groupTemplate')->name('group-template');
                Route::post('/import', 'import')->name('import');
            });

            Route::prefix('{employee}')->whereNumber('employee')->group(function () {
                Route::get('/profile', [EmployeeProfileController::class, 'show'])->name('profile');
                Route::put('/profile', [EmployeeProfileController::class, 'update'])->name('profile.update');

                Route::post('/termination', [EmployeeTerminationController::class, 'store'])->name('termination.store');
                Route::delete('/termination', [EmployeeTerminationController::class, 'destroy'])->name('termination.destroy');

                // Placement: work location + SubGroup + unit (Ubah Penempatan), or straight into a position (bagan "Kelola").
                Route::put('/placement', [EmployeePlacementController::class, 'update'])->name('placement.update');
                Route::put('/position', [EmployeePlacementController::class, 'assign'])->name('position.assign');
                Route::post('/sub-locations', [EmployeePlacementController::class, 'subLocation'])->name('sub-locations');
                Route::post('/remove', [EmployeePlacementController::class, 'remove'])->name('remove');

                Route::post('/contract', [EmployeeContractController::class, 'store'])->name('contract.store');
                Route::put('/contract/{contract}', [EmployeeContractController::class, 'update'])->name('contract.update');
                Route::delete('/contract/{contract}', [EmployeeContractController::class, 'destroy'])->name('contract.destroy');

                Route::post('/children', [EmployeeChildController::class, 'store'])->name('children.store');
                Route::put('/children/{children}', [EmployeeChildController::class, 'update'])->name('children.update');
                Route::delete('/children/{children}', [EmployeeChildController::class, 'destroy'])->name('children.destroy');
            });
        });
    });

    // Attendance: Roster (plan), Absensi (actual), Trip & Leave and its Pengajuan Dinas list.
    Route::prefix('time')->name('time.')->group(function () {
        // Both sheets share one controller; the URL segment picks the sheet ("type") and its menu.
        foreach (SheetController::PATH as $type => $segment) {
            Route::controller(SheetController::class)->prefix($segment)->middleware('menu:'.SheetController::MENU[$type])->name("sheet.{$type}.")->group(function () use ($type) {
                Route::get('/', 'show')->defaults('type', $type)->name('show');
                Route::post('/save', 'save')->defaults('type', $type)->name('save');
                Route::post('/import', 'import')->defaults('type', $type)->name('import');
                Route::post('/import/save', 'importSave')->defaults('type', $type)->name('import.save');
                Route::get('/export', 'export')->defaults('type', $type)->name('export');
            });
        }

        Route::controller(LeaveTravelController::class)->group(function () {
            Route::middleware('menu:leave-travel')->group(function () {
                Route::get('/leave-travel', 'index')->name('leave_travel');
                Route::post('/leave-travel', 'store')->name('leave_travel.store');
            });
            Route::middleware('menu:leave-travel,travel-requests')->group(function () {
                Route::put('/leave-travel/{leave_travel}', 'update')->name('leave_travel.update');
                Route::delete('/leave-travel/{leave_travel}', 'destroy')->name('leave_travel.destroy');
                Route::get('/leave-travel/export', 'export')->name('leave_travel.export');
                // The cuti form: balance, field-leave allowance and Cuti Besar of the employee.
                Route::get('/leave-info/{employee}', [LeaveBalanceController::class, 'info'])->whereNumber('employee')->name('leave-info');
            });
            Route::middleware('menu:travel-requests')->group(function () {
                Route::put('/leave-travel/{leave_travel}/cash-status', 'cashAdvanceStatus')->name('leave_travel.cash-status');
                Route::put('/leave-travel/{leave_travel}/ticket', 'ticket')->name('leave_travel.ticket');
                Route::get('/travel-requests/template', 'template')->name('submission.template');
                Route::post('/travel-requests/import', 'import')->name('submission.import');
                Route::post('/leave-travel/{leave_travel}/verification', 'verify')->name('leave_travel.verify');
                Route::get('/travel-requests', 'list')->name('submission');
            });
        });

        // Kehadiran: CI/CO from the attendance machines, its resume, overtime and the machines themselves.
        Route::redirect('/overtime', '/time/clock?tab=overtime')->name('overtime');
        Route::controller(TimeClockController::class)->prefix('clock')->name('time_clock')->group(function () {
            Route::get('/', 'index')->middleware('menu:time-clock,overtime-approval');
            Route::middleware('menu:time-clock')->group(function () {
                Route::post('/process', 'process')->name('.process');
                Route::post('/log', 'storeLog')->name('.log.store');
                Route::delete('/log/{log}', 'destroyLog')->whereNumber('log')->name('.log.destroy');
                Route::post('/import', 'import')->name('.import');
                Route::post('/device', 'storeDevice')->name('.device.store');
                Route::put('/device/{device}', 'updateDevice')->whereNumber('device')->name('.device.update');
                Route::delete('/device/{device}', 'destroyDevice')->whereNumber('device')->name('.device.destroy');
            });
            Route::post('/overtime/verification', 'verifyOvertime')->middleware('menu:overtime-approval')->name('.overtime.verify');
        });

        // Leave balances: Cuti Tahunan & Cuti Besar.
        Route::controller(LeaveBalanceController::class)->middleware('menu:leave-balances')->prefix('leave-balances')->name('leave')->group(function () {
            Route::get('/', 'index');
            Route::get('/export', 'export')->name('.export');
            Route::get('/employee/{employee}', 'show')->whereNumber('employee')->name('.show');
            Route::post('/employee/{employee}/transaction', 'storeTransaction')->whereNumber('employee')->name('.transaction.store');
            Route::delete('/transaction/{transaction}', 'destroyTransaction')->whereNumber('transaction')->name('.transaction.destroy');
            Route::post('/employee/{employee}/long-service', 'decideLongService')->whereNumber('employee')->name('.long_service.decide');
            Route::post('/long-service/{longServiceLeave}/verification', 'verifyLongService')->whereNumber('longServiceLeave')->name('.long_service.verify');
        });

        Route::controller(TimeSettingsController::class)->middleware('menu:time-settings')->prefix('settings')->name('master.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/shift-codes', 'storeCode')->name('shift-codes.store');
            Route::put('/shift-codes/{shiftCode}', 'updateCode')->name('shift-codes.update');
            Route::delete('/shift-codes/{shiftCode}', 'destroyCode')->name('shift-codes.destroy');
            Route::post('/shift-patterns', 'storePattern')->name('shift-patterns.store');
            Route::put('/shift-patterns/{rosterPattern}', 'updatePattern')->name('shift-patterns.update');
            Route::delete('/shift-patterns/{rosterPattern}', 'destroyPattern')->name('shift-patterns.destroy');
            Route::post('/public-holidays', 'storeHoliday')->name('public-holidays.store');
            Route::put('/public-holidays/{holiday}', 'updateHoliday')->name('public-holidays.update');
            Route::delete('/public-holidays/{holiday}', 'destroyHoliday')->name('public-holidays.destroy');
            Route::put('/work-schedule/{workLocation}', 'updateWorkSchedule')->whereNumber('workLocation')->name('work-schedule.update');
            Route::put('/overtime-policy', 'updateOvertimePolicy')->name('overtime-policy.update');
            Route::put('/overtime-eligibility', 'updateOvertimeEligibility')->name('overtime-eligibility.update');
        });
    });

    // Comben: Deklarasi Pengobatan / Kacamata against the plafon, its monitoring and master data.
    Route::prefix('compensation')->name('compensation.')->group(function () {
        Route::controller(BenefitClaimController::class)->middleware('menu:benefit-claims')->group(function () {
            Route::get('/claims', 'index')->name('claim');
            Route::get('/claims/export', 'export')->name('claim.export');
            Route::post('/claims', 'store')->name('claim.store');
            Route::put('/claims/{claim}', 'update')->name('claim.update');
            Route::delete('/claims/{claim}', 'destroy')->name('claim.destroy');
            Route::post('/claims/{claim}/verification', 'verify')->name('claim.verify');
            Route::get('/employee/{employee}/benefit-limit', 'info')->whereNumber('employee')->name('employee.benefit_limit');
        });
        Route::get('/benefit-balances', [BenefitBalanceController::class, 'index'])->middleware('menu:benefit-balances')->name('monitoring');
        Route::get('/benefit-balances/export', [BenefitBalanceController::class, 'export'])->middleware('menu:benefit-balances')->name('monitoring.export');


        // Data Gaji: golongan & step in the Struktur Skala Upah, Tunjangan Jabatan, PTKP.
        Route::controller(SalaryController::class)->middleware('menu:employee-compensation')->prefix('salaries')->name('salary')->group(function () {
            Route::get('/', 'index');
            Route::post('/{employee}', 'store')->whereNumber('employee')->name('.store');
            Route::delete('/history/{salary}', 'destroy')->whereNumber('salary')->name('.destroy');
        });

        // Simulasi Gaji: one slip worked out live by the payroll engine; the employee's payroll choices.
        Route::controller(PayrollSimulationController::class)->middleware('menu:employee-compensation')->group(function () {
            Route::get('/payroll-simulation', 'index')->name('payroll-simulation');
            Route::put('/payroll-profile/{employee}', 'updateProfile')->whereNumber('employee')->name('payroll-profile.update');
        });

        // Proses Gaji: payroll runs worked out ("payroll-run") and approved, paid, locked ("payroll-approval").
        Route::controller(PayrollRunController::class)->prefix('payroll')->name('payroll')->middleware('two-factor.required')->group(function () {
            Route::middleware('menu:payroll-run,payroll-approval')->group(function () {
                Route::get('/', 'index');
                Route::get('/{run}', 'show')->whereNumber('run')->name('.show');
                Route::get('/{run}/export', 'export')->whereNumber('run')->name('.export');
                Route::get('/{run}/slips/{payslip}', 'slip')->whereNumber(['run', 'payslip'])->name('.slip');
                Route::get('/{run}/slips/{payslip}/pdf', 'pdf')->whereNumber(['run', 'payslip'])->name('.slip.pdf');
            });
            Route::middleware('menu:payroll-run')->group(function () {
                Route::post('/', 'store')->name('.store');
                Route::post('/{run}/calculate', 'calculate')->whereNumber('run')->name('.calculate');
                Route::post('/{run}/submit', 'submit')->whereNumber('run')->name('.submit');
                Route::delete('/{run}', 'destroy')->whereNumber('run')->name('.destroy');
            });
            Route::middleware('menu:payroll-approval')->group(function () {
                Route::post('/{run}/approve', 'approve')->whereNumber('run')->name('.approve');
                Route::post('/{run}/reject', 'reject')->whereNumber('run')->name('.reject');
                Route::post('/{run}/paid', 'paid')->whereNumber('run')->name('.paid');
                Route::post('/{run}/lock', 'lock')->whereNumber('run')->name('.lock');
            });
        });

        // Input Variabel: amounts per period, entered ("payroll-inputs") and approved ("payroll-approval").
        Route::controller(PayrollInputController::class)->prefix('payroll-inputs')->name('payroll-inputs')->middleware('two-factor.required')->group(function () {
            Route::middleware('menu:payroll-inputs,payroll-approval')->get('/', 'index');
            Route::middleware('menu:payroll-inputs')->group(function () {
                Route::get('/template', 'template')->name('.template');
                Route::post('/', 'store')->name('.store');
                Route::post('/import', 'import')->name('.import');
                Route::put('/{input}', 'update')->whereNumber('input')->name('.update');
                Route::delete('/{input}', 'destroy')->whereNumber('input')->name('.destroy');
            });
            Route::middleware('menu:payroll-approval')->post('/decide', 'decide')->name('.decide');
        });

        // Pinjaman Karyawan: recorded ("loans"), approved ("payroll-approval"), repaid through Proses Gaji.
        Route::controller(LoanController::class)->prefix('loans')->name('loans')->middleware('two-factor.required')->group(function () {
            Route::middleware('menu:loans,payroll-approval')->get('/', 'index');
            Route::middleware('menu:loans')->group(function () {
                Route::post('/', 'store')->name('.store');
                Route::put('/{loan}', 'update')->whereNumber('loan')->name('.update');
                Route::delete('/{loan}', 'destroy')->whereNumber('loan')->name('.destroy');
                Route::post('/installments/{installment}/postpone', 'postpone')->whereNumber('installment')->name('.postpone');
                Route::post('/{loan}/pay-off', 'payOff')->whereNumber('loan')->name('.pay-off');
            });
            Route::middleware('menu:payroll-approval')->group(function () {
                Route::post('/{loan}/approve', 'approve')->whereNumber('loan')->name('.approve');
                Route::post('/{loan}/reject', 'reject')->whereNumber('loan')->name('.reject');
            });
        });

        Route::controller(PayrollComponentController::class)->middleware('menu:compensation-settings')->prefix('settings/payroll-components')->name('payroll-components')->group(function () {
            Route::post('/', 'store')->name('.store');
            Route::put('/{component}', 'update')->whereNumber('component')->name('.update');
            Route::delete('/{component}', 'destroy')->whereNumber('component')->name('.destroy');
        });

        Route::controller(CompensationSettingsController::class)->middleware('menu:compensation-settings')->prefix('settings')->name('master.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/grade', 'storeGrade')->name('grade.store');
            Route::put('/grade/{grade}', 'updateGrade')->name('grade.update');
            Route::delete('/grade/{grade}', 'destroyGrade')->name('grade.destroy');
            Route::post('/salary-scale', 'storeScale')->name('scale.store');
            Route::put('/salary-scale/{salaryScale}', 'updateScale')->name('scale.update');
            Route::delete('/salary-scale/{salaryScale}', 'destroyScale')->name('scale.destroy');
            Route::post('/salary-scale-periods', 'storePeriodColumn')->name('period_column.store');
            Route::delete('/salary-scale-periods/{period_column}', 'destroyPeriodColumn')->name('period_column.destroy');
            Route::post('/benefit-limit/{type}', 'storeBenefitLimit')->whereIn('type', ['medical', 'optical'])->name('benefit_limit.store');
            Route::put('/benefit-limit/{type}/{id}', 'updateBenefitLimit')->whereIn('type', ['medical', 'optical'])->whereNumber('id')->name('benefit_limit.update');
            Route::delete('/benefit-limit/{type}/{id}', 'destroyBenefitLimit')->whereIn('type', ['medical', 'optical'])->whereNumber('id')->name('benefit_limit.destroy');
            Route::put('/leave-policy', [LeaveSettingsController::class, 'updatePolicy'])->name('leave-policy.update');
            Route::post('/field-leave', [LeaveSettingsController::class, 'storeFieldLeave'])->name('field-leave.store');
            Route::put('/field-leave/{field}', [LeaveSettingsController::class, 'updateFieldLeave'])->whereNumber('field')->name('field-leave.update');
            Route::delete('/field-leave/{field}', [LeaveSettingsController::class, 'destroyFieldLeave'])->whereNumber('field')->name('field-leave.destroy');
            Route::put('/field-leave-allowances', [LeaveSettingsController::class, 'updateFieldLeaveAllowance'])->name('field-leave-allowances.update');
            Route::put('/position-allowances', 'updateAllowance')->name('position-allowances.update');
            Route::put('/payroll', 'updatePayroll')->name('payroll.update');
            Route::put('/payroll/rates', 'updatePayrollRates')->name('payroll.rates.update');
        });
    });

    // Settings → Akun & Akses: Super Admin only.
    Route::middleware('can:manage-users')->prefix('settings')->name('settings.')->controller(UserController::class)->group(function () {
        Route::get('/users', 'index')->name('users');
        Route::post('/users', 'store')->name('users.store');
        Route::put('/users/{user}', 'update')->whereNumber('user')->name('users.update');
        Route::post('/users/{user}/two-factor-reset', [TwoFactorController::class, 'reset'])->whereNumber('user')->name('users.two-factor-reset');
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log');
        Route::get('/license', [LicenseController::class, 'show'])->name('license');
        Route::post('/license', [LicenseController::class, 'store'])->name('license.store');
        Route::delete('/users/{user}', 'destroy')->whereNumber('user')->name('users.destroy');
        Route::post('/employee-accounts', 'storeEmployee')->name('employee-accounts.store');
        Route::post('/employee-accounts/bulk-reset', 'resetEmployeesBulk')->name('employee-accounts.reset-many');
        Route::post('/employee-accounts/{user}/reset', 'resetEmployee')->whereNumber('user')->name('employee-accounts.reset');
        Route::post('/employee-accounts/{user}/status', 'toggleEmployee')->whereNumber('user')->name('employee-accounts.toggle');
        Route::delete('/employee-accounts/{user}', 'destroyEmployee')->whereNumber('user')->name('employee-accounts.destroy');
    });
    // Sesi awal: first-run setup of a workspace for the company's own data (Super Admin).
    Route::middleware('can:manage-users')->prefix('setup')->controller(SetupController::class)->group(function () {
        Route::get('/', 'show')->name('setup');
        Route::post('/steps/{step}', 'step')->name('setup.step');
        Route::post('/organization', 'organization')->name('setup.organization');
        Route::post('/finish', 'finish')->name('setup.finish');
        Route::post('/dismiss', 'dismiss')->name('setup.dismiss');
    });
    // Settings → Akun Demo: the demo requests and their workspaces (main application, Super Admin).
    Route::middleware(['saas', 'can:manage-users', 'central'])->prefix('settings/trial-accounts')->name('settings.demo')->controller(TrialAccountController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/{account}/approve', 'approve')->whereNumber('account')->name('.approve');
        Route::post('/{account}/extend', 'extend')->whereNumber('account')->name('.extend');
        Route::put('/{account}/ends', 'setExpiry')->whereNumber('account')->name('.expiry');
        Route::post('/{account}/status', 'toggle')->whereNumber('account')->name('.toggle');
        Route::post('/{account}/reset', 'reset')->whereNumber('account')->name('.reset');
        Route::delete('/{account}', 'destroy')->whereNumber('account')->name('.destroy');
    });
    // Settings → White Label: Super Admin only.
    Route::middleware('can:manage-users')->prefix('settings')->name('settings.')->controller(BrandingController::class)->group(function () {
        Route::get('/branding', 'edit')->name('branding');
        Route::post('/branding', 'update')->name('branding.update');
    });

    // The bell: pengajuan from the portal waiting for this account.
    Route::controller(NotificationController::class)->prefix('notifications')->name('notifications')->group(function () {
        Route::get('/', 'index');
        Route::get('/quantity', 'count')->name('.count');
        Route::post('/read-all', 'readAll')->name('.read-all');
        Route::post('/{id}/read', 'read')->whereUuid('id')->name('.read');
    });

    // A nota / signed form, for whoever may see its deklarasi or cuti / dinas.
    Route::get('/attachment/{attachment}', AttachmentController::class)->middleware('scoped')->whereNumber('attachment')->name('attachment.show');

    // Portal Karyawan: an employee's own plafon and pengajuan (deklarasi, cuti, dinas).
    Route::middleware(['can:self_service', 'scoped'])->prefix('self-service')->name('self_service')->controller(SelfServiceController::class)->group(function () {
        Route::get('/', 'home');
        Route::get('/benefit-limit', 'benefitLimit')->name('.benefit_limit');
        Route::post('/claim', 'storeClaim')->name('.claim');
        Route::post('/leave-travel', 'storeTrip')->name('.leave_travel');
        Route::delete('/submission/{type}/{id}', 'cancel')->whereIn('type', ['claim', 'leave-travel'])->whereNumber('id')->name('.cancel');
        // Data Saya: changes to the own data, sent to HR.
        Route::get('/profile', [PortalDataController::class, 'show'])->name('.data');
        Route::post('/profile', [PortalDataController::class, 'store'])->name('.data.store');
        Route::delete('/profile/{id}', [PortalDataController::class, 'cancel'])->whereNumber('id')->name('.data.cancel');
        // Own leave balance for the cuti form, and the Cuti Besar choice (checked by HR).
        Route::get('/leave-info', 'leaveInfo')->name('.leave-info');
        Route::post('/long-service-leave', 'chooseLongService')->name('.long-service-leave');
        // Slip Gaji: own payslips of approved payroll runs.
        Route::get('/payslips', [PortalPayslipController::class, 'index'])->name('.payslips');
        Route::get('/payslips/{payslip}/pdf', [PortalPayslipController::class, 'pdf'])->whereNumber('payslip')->name('.payslips.pdf');
        // Pinjaman: own loans, asking for one.
        Route::get('/loans', [PortalLoanController::class, 'index'])->name('.loans');
        Route::post('/loans', [PortalLoanController::class, 'store'])->name('.loans.store');
        Route::delete('/loans/{loan}', [PortalLoanController::class, 'cancel'])->whereNumber('loan')->name('.loans.cancel');
    });
});

// Page URLs from before the terminology refactor: 301 to where they live now.
foreach (array_keys(LegacyUrls::MAP) as $old) {
    Route::get($old.'/{rest?}', LegacyRedirectController::class)->defaults('old', $old)->where('rest', '.*');
}
