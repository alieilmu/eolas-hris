<?php

namespace Tests\Feature;

use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\Attachment;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-05 09:00:00');
        CarbonImmutable::setTestNow('2026-10-05 09:00:00');
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hr(): User
    {
        return User::where('email', 'hr@eolas.local')->firstOrFail();
    }

    private function employee(): Employee
    {
        $k = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->whereHas('jobLevel', fn ($q) => $q->where('name', 'Operator'))->firstOrFail();
        $k->profile->update(['birth_date' => '1992-04-10', 'marital_status' => 'Menikah', 'spouse_name' => 'Siti', 'grade' => 'D2J']);

        return $k;
    }

    private function portalUser(Employee $k): User
    {
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/employee-accounts', ['employee_ids' => [$k->id]]);

        return tap(User::where('employee_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);
    }

    private function claim(Employee $k, array $extra = []): array
    {
        return ['employee_id' => $k->id, 'date' => '2026-10-02', 'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 150000]], ...$extra];
    }

    private function pdf(string $name = 'receipt_doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    public function test_a_deklarasi_needs_its_nota_and_counts_them(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());

        $this->post('/compensation/claims', $this->claim($k))->assertSessionHasErrors(['attachment' => 'Lampirkan foto / PDF nota (minimal satu).']);
        $this->post('/compensation/claims', $this->claim($k, ['attachment' => [UploadedFile::fake()->create('receipt_doc.docx', 10)]]))->assertSessionHasErrors('attachment.0');

        // Two photos and a PDF of three pages: five nota.
        $this->post('/compensation/claims', $this->claim($k, [
            'attachment' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), $this->pdf()],
            'page_count' => [null, null, 3],
        ]))->assertSessionHasNoErrors();
        $d = BenefitClaim::with('attachments')->firstOrFail();
        $this->assertSame(5, $d->receipt_count);
        $this->assertCount(3, $d->attachments);
        Storage::disk('local')->assertExists($d->attachments[0]->path);

        // Taking one photo out and adding none: four nota; taking all out is refused.
        $ids = $d->attachments->pluck('id');
        $this->post("/compensation/claims/{$d->id}", [...$this->claim($k), '_method' => 'put', 'delete_attachments' => [$ids[0]]])->assertSessionHasNoErrors();
        $this->assertSame(4, $d->fresh()->receipt_count);
        Storage::disk('local')->assertMissing($d->attachments[0]->path);
        $this->post("/compensation/claims/{$d->id}", [...$this->claim($k), '_method' => 'put', 'delete_attachments' => $ids->all()])->assertSessionHasErrors('attachment');

        // Deleting the deklarasi deletes its files.
        $path = $d->attachments[2]->path;
        $this->delete("/compensation/claims/{$d->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_only_the_employee_and_admins_of_the_menu_open_a_lampiran(): void
    {
        $k = $this->employee();
        $user = $this->portalUser($k);
        $this->actingAs($user)->post('/self-service/claim', [
            'date' => '2026-10-05', 'items' => [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 100000]],
            'attachment' => [UploadedFile::fake()->image('receipt_doc.jpg')],
        ])->assertSessionHasNoErrors();
        $attachment = Attachment::firstOrFail();

        $this->get("/attachment/{$attachment->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->hr())->get("/attachment/{$attachment->id}")->assertOk();

        // Another employee: not theirs.
        $other = Employee::where('status', Employee::STATUS_ACTIVE)->where('id', '!=', $k->id)->firstOrFail();
        $this->actingAs($this->portalUser($other))->get("/attachment/{$attachment->id}")->assertNotFound();
        // An admin without Deklarasi / Monitoring.
        $viewer = User::where('email', 'viewer@eolas.local')->firstOrFail();
        $this->actingAs($viewer)->get("/attachment/{$attachment->id}")->assertForbidden();
    }

    public function test_cuti_and_dinas_need_the_signed_form_and_can_ask_for_tickets(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());
        $leave_travel = fn (array $extra) => $this->post('/time/leave-travel', [
            'type' => 'business_trip', 'employee_id' => $k->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
            'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', ...$extra,
        ]);

        $leave_travel([])->assertSessionHasErrors(['attachment' => 'Lampirkan foto / PDF form dinas yang sudah disetujui.']);
        $leave_travel(['attachment' => [$this->pdf('st.pdf')], 'needs_flight' => '1'])->assertSessionHasErrors(['departure_airport', 'arrival_airport']);
        $leave_travel(['attachment' => [$this->pdf('st.pdf')], 'needs_flight' => '1', 'departure_airport' => 'TJG', 'arrival_airport' => 'TJG'])->assertSessionHasErrors('arrival_airport');
        $leave_travel(['attachment' => [$this->pdf('st.pdf')], 'needs_flight' => '1', 'departure_airport' => 'XXX', 'arrival_airport' => 'CGK'])->assertSessionHasErrors('departure_airport');

        $leave_travel(['attachment' => [$this->pdf('st.pdf')], 'needs_flight' => '1', 'departure_airport' => 'BDJ', 'arrival_airport' => 'CGK', 'is_round_trip' => '1',
            'return_date' => '2026-10-22', 'return_departure_airport' => 'CGK', 'return_arrival_airport' => 'BDJ', 'needs_train' => '1', 'description' => 'Gambir → Bandung'])
            ->assertSessionHasNoErrors();
        $t = LeaveTravelRequest::with('attachments')->firstOrFail();
        $this->assertSame([true, 'BDJ', 'CGK', true, true], [$t->needs_flight, $t->departure_airport, $t->arrival_airport, $t->is_round_trip, $t->needs_train]);
        $this->assertCount(1, $t->attachments);

        // Cuti: no train ticket; no flight clears the airports.
        $this->put("/time/leave-travel/{$t->id}", [
            'type' => 'leave', 'leave_category' => 'other', 'employee_id' => $k->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
            'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', 'needs_flight' => '0', 'departure_airport' => 'BDJ', 'needs_train' => '1',
        ])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame([false, null, null, false, false], [$t->needs_flight, $t->departure_airport, $t->arrival_airport, $t->is_round_trip, $t->needs_train]);
    }

    public function test_a_round_trip_flight_a_train_and_uang_muka(): void
    {
        $k = $this->employee();
        $this->actingAs($this->hr());
        $leave_travel = fn (array $extra) => $this->post('/time/leave-travel', [
            'type' => 'business_trip', 'employee_id' => $k->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
            'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', 'attachment' => [$this->pdf('st.pdf')],
            'needs_flight' => '1', 'departure_airport' => 'TJG', 'arrival_airport' => 'CGK', ...$extra,
        ]);

        // PP: the way back is asked for.
        $leave_travel(['is_round_trip' => '1'])->assertSessionHasErrors(['return_date', 'return_departure_airport', 'return_arrival_airport']);
        $leave_travel(['is_round_trip' => '1', 'return_date' => '2026-10-19', 'return_departure_airport' => 'CGK', 'return_arrival_airport' => 'CGK'])
            ->assertSessionHasErrors(['return_date', 'return_arrival_airport']);
        // Train: the stations go in Keterangan. Uang muka: its amount.
        $leave_travel(['needs_train' => '1'])->assertSessionHasErrors('description');
        $leave_travel(['needs_cash_advance' => '1'])->assertSessionHasErrors(['cash_advance_amount' => 'Isi jumlah uang muka yang diajukan.']);

        $leave_travel([
            'is_round_trip' => '1', 'return_date' => '2026-10-22', 'return_departure_airport' => 'CGK', 'return_arrival_airport' => 'BDJ',
            'needs_train' => '1', 'description' => 'Gambir → Bandung', 'needs_cash_advance' => '1', 'cash_advance_amount' => '2500000',
        ])->assertSessionHasNoErrors();
        $t = LeaveTravelRequest::firstOrFail();
        $this->assertSame(['2026-10-22', 'CGK', 'BDJ'], [$t->return_date->toDateString(), $t->return_departure_airport, $t->return_arrival_airport]);
        $this->assertSame([true, 2500000, 'Diajukan'], [$t->needs_cash_advance, $t->cash_advance_amount, $t->cash_advance_status]);

        // As cuti: no train, no uang muka; without PP the way back is cleared.
        $this->put("/time/leave-travel/{$t->id}", [
            'type' => 'leave', 'leave_category' => 'other', 'employee_id' => $k->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
            'origin_city' => 'Tanjung', 'destination_city' => 'Jakarta', 'needs_flight' => '1', 'departure_airport' => 'TJG', 'arrival_airport' => 'CGK',
            'is_round_trip' => '0', 'return_date' => '2026-10-22', 'needs_train' => '1', 'needs_cash_advance' => '1', 'cash_advance_amount' => '100',
        ])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame([false, null, false, false, null], [$t->is_round_trip, $t->return_date, $t->needs_train, $t->needs_cash_advance, $t->cash_advance_amount]);
    }
}
