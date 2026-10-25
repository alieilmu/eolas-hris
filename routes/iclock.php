<?php

use App\Http\Controllers\Time\IclockController;
use Illuminate\Support\Facades\Route;

// ADMS push from attendance machines (Attendance → Kehadiran → Mesin Absen): no session,
// no CSRF; machines are told apart by serial number. Some firmware adds ".aspx".
Route::middleware('throttle:iclock')->prefix('iclock')->controller(IclockController::class)->group(function () {
    Route::match(['get', 'post'], '/cdata{ext?}', 'cdata')->where('ext', '\.aspx');
    Route::get('/getrequest{ext?}', 'getrequest')->where('ext', '\.aspx');
    Route::post('/devicecmd{ext?}', 'devicecmd')->where('ext', '\.aspx');
});
