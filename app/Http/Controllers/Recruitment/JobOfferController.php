<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateStageHistory;
use App\Models\JobOffer;
use App\Models\Position;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Workflow\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Penawaran Kerja: HR proposes the offer to a candidate (position, location, salary, start
 * date); it goes through the "job-offer" Workflow — by default HR of Recruitment at that
 * location, or as the flow says (e.g. the superior of the position offered) — and once
 * approved, the candidate is hired with it.
 */
class JobOfferController extends Controller
{
    public function store(Request $request, Candidate $candidate): JsonResponse
    {
        if ($candidate->employee()->exists()) {
            throw ValidationException::withMessages(['position_id' => "{$candidate->name} sudah menjadi karyawan."]);
        }
        if ($candidate->offers()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['position_id' => 'Masih ada penawaran yang menunggu persetujuan. Batalkan dulu bila ingin mengubahnya.']);
        }
        $data = $request->validate([
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')],
            'work_location_id' => ['nullable', 'integer', Rule::exists('work_locations', 'id')],
            'salary' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['position_id' => 'Jabatan', 'work_location_id' => 'Lokasi kerja', 'salary' => 'Gaji yang ditawarkan', 'start_date' => 'Tanggal mulai']);
        $data['work_location_id'] ??= Position::find($data['position_id'])?->work_location_id;

        $offer = DB::transaction(function () use ($candidate, $data, $request) {
            $offer = $candidate->offers()->create($data + ['status' => 'pending', 'created_by' => $request->user()->id]);
            Workflow::start($offer, 'job-offer', $offer->standIn(), $request->user(), ['amount' => (int) $offer->salary], $offer->summary(), $offer->workflowNotice()['url']);
            CandidateStageHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => 'Penawaran diajukan', 'changed_by' => $request->user()->name]);

            return $offer;
        });
        Audit::log('recruitment.offer', $offer, $offer->summary());

        return response()->json(['offer' => self::share($offer->fresh(), $request->user())], 201);
    }

    /** HR withdraws a waiting offer (to change it, or the candidate declined first). */
    public function cancel(Request $request, JobOffer $offer): JsonResponse
    {
        abort_unless($offer->status === 'pending', 422, 'Penawaran ini sudah diputuskan.');
        DB::transaction(function () use ($offer, $request) {
            Workflow::withdraw($offer, "Dibatalkan oleh {$request->user()->name}.");
            $offer->update(['status' => 'cancelled', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            CandidateStageHistory::create(['candidate_id' => $offer->candidate_id, 'from' => $offer->candidate?->status, 'to' => 'Penawaran dibatalkan', 'changed_by' => $request->user()->name]);
        });

        return response()->json(['ok' => true]);
    }

    /** An offer as the candidate window shows it, with where it waits in its flow. */
    public static function share(JobOffer $o, User $user): array
    {
        $o->loadMissing('position:id,name', 'workLocation:id,name');
        $flow = $o->status === 'pending' ? Workflow::of($o) : null;

        return [
            'id' => $o->id,
            'position' => $o->position?->name,
            'position_id' => $o->position_id,
            'work_location' => $o->workLocation?->name,
            'work_location_id' => $o->work_location_id,
            'salary' => $o->salary,
            'start_date' => $o->start_date?->toDateString(),
            'notes' => $o->notes,
            'status' => $o->status,
            'status_label' => JobOffer::STATUS[$o->status] ?? $o->status,
            'decision_notes' => $o->decision_notes,
            'created_at' => $o->created_at?->toIso8601String(),
            'workflow' => $flow?->isPending() ? Workflow::brief($flow, $user) : null,
        ];
    }
}
