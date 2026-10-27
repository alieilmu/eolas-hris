<?php

namespace App\Http\Controllers;

use App\Models\BenefitClaim;
use App\Models\Attachment;
use App\Models\EmployeeCertification;
use App\Models\EmployeeChangeRequest;
use App\Models\LeaveTravelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a nota / signed form inline, for the employee it belongs to or an admin who
 * may see its menu (Deklarasi / Monitoring, or Cuti & Dinas / Pengajuan Dinas) and
 * whose locations include that employee.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        // Through the record's own access scope: someone outside the account's locations is not found.
        $owner = $attachment->attachable()->first();
        abort_unless($owner instanceof BenefitClaim || $owner instanceof LeaveTravelRequest || $owner instanceof EmployeeChangeRequest || $owner instanceof EmployeeCertification, 404);

        $user = $request->user();
        $allowed = $user->isEmployee()
            ? $owner->employee_id === $user->employee_id
            : match (true) {
                $owner instanceof BenefitClaim => $user->canView('benefit-claims', 'benefit-balances'),
                $owner instanceof LeaveTravelRequest => $user->canView('leave-travel', 'travel-requests'),
                $owner instanceof EmployeeCertification => $user->canView('certifications', 'employees'),
                default => $user->canView('data-change-requests', 'employees'),
            };
        abort_unless($allowed, 403);
        abort_unless(Storage::disk(Attachment::DISK)->exists($attachment->path), 404);

        return Storage::disk(Attachment::DISK)->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
