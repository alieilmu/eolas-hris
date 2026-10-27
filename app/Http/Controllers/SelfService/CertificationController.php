<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\CoreHr\CertificationController as HrCertificationController;
use App\Http\Controllers\Controller;
use App\Models\EmployeeCertification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Portal Karyawan → Sertifikat: the own licences and certificates and until when they run. */
class CertificationController extends Controller
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $rows = EmployeeCertification::with(['type', 'attachments'])->where('employee_id', $request->user()->employee_id ?? 0)->get()
            ->sortBy(fn ($c) => $c->expires_at?->format('Y-m-d') ?? '9999')->values();

        return Inertia::render('SelfService/Certifications', ['rows' => $rows->map(fn ($c) => HrCertificationController::share($c, $today))]);
    }
}
