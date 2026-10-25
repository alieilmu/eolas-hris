<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeChangeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
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

    /** @return array{0: Karyawan, 1: User} */
    private function employee(): array
    {
        $k = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->firstOrFail();
        $k->profile->update(['birth_date' => '1990-01-31', 'religion' => 'Islam', 'national_id_number' => null, 'employment_status' => 'Tetap']);
        $profile = $k->profile;
        $profile->children()->delete();
        $profile->children()->create(['name' => 'ANAK SATU', 'birth_date' => '2015-03-01', 'sort_order' => 1]);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail())->post('/settings/employee-accounts', ['employee_ids' => [$k->id]]);
        $user = tap(User::where('employee_id', $k->id)->firstOrFail())->update(['must_change_password' => false]);

        return [$k->fresh('profile.children'), $user];
    }

    public function test_an_employee_asks_and_hr_approves(): void
    {
        [$k, $user] = $this->employee();
        $children = $k->profile->children->first();
        $this->actingAs($user);

        $this->get('/self-service/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SelfService/Profile')
            ->where('profile.religion', 'Islam')
            ->has('children', 1)
            ->where('pending', null)
            // HR-only fields are not on the form.
            ->where('sections', fn ($s) => ! collect($s)->pluck('id')->intersect(['identity', 'grade', 'contract', 'appointment'])->count()
                && ! collect($s)->flatMap(fn ($x) => collect($x['fields'] ?? [])->pluck('key'))->contains('employment_status')));

        // Nothing changed.
        $this->post('/self-service/profile', ['religion' => 'Islam'])->assertSessionHasErrors('changes');
        // A wrong NIK.
        $this->post('/self-service/profile', ['national_id_number' => '123'])->assertSessionHasErrors('national_id_number');

        $this->post('/self-service/profile', [
            'religion' => 'Islam', 'national_id_number' => '3209011609820001', 'phone' => '081234567890', 'employment_status' => 'PKWT', 'grade' => 'D1J',
            'children' => [
                ['id' => $children->id, 'name' => 'ANAK SATU', 'birth_date' => '2015-03-02'],
                ['name' => 'ANAK DUA', 'birth_date' => '2019-07-10', 'education_level' => 'TK'],
            ],
            'notes' => 'KTP baru',
            'attachment' => [UploadedFile::fake()->image('ktp.jpg')],
        ])->assertSessionHasNoErrors();

        $p = EmployeeChangeRequest::firstOrFail();
        $this->assertSame(['national_id_number', 'phone'], array_keys($p->changes));
        $this->assertSame(['edit', 'add'], array_column($p->children, 'action'));
        $this->assertCount(1, $p->attachments);
        // Not applied yet, and one request at a time.
        $this->assertNull($k->profile->fresh()->national_id_number);
        $this->post('/self-service/profile', ['phone' => '089999999999'])->assertSessionHasErrors('changes');

        // HR sees it and approves.
        $this->actingAs($this->hr())->get('/core-hr/change-requests')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('CoreHr/ChangeRequests')
            ->where('counts.pending', 1)
            ->where('items.data.0.quantity', 4)
            ->where('fields.national_id_number.label', 'NIK (KTP)'));
        $this->get("/attachment/{$p->attachments[0]->id}")->assertOk();
        $this->post("/core-hr/change-requests/{$p->id}/verification", ['decision' => 'approve'])->assertSessionHasNoErrors();

        $profile = $k->profile->fresh('children');
        $this->assertSame(['3209011609820001', '081234567890', 'Tetap'], [$profile->national_id_number, $profile->phone, $profile->employment_status]);
        $this->assertSame([['ANAK SATU', '2015-03-02', 1], ['ANAK DUA', '2019-07-10', 2]],
            $profile->children->map(fn (EmployeeChild $a) => [$a->name, $a->birth_date->toDateString(), $a->sort_order])->all());
        $this->assertSame('approved', $p->fresh()->status);
    }

    public function test_rejecting_removing_a_child_and_cancelling(): void
    {
        [$k, $user] = $this->employee();
        $children = $k->profile->children->first();

        $this->actingAs($user)->post('/self-service/profile', ['children' => [['id' => $children->id, 'name' => 'ANAK SATU', 'delete' => true]]])->assertSessionHasNoErrors();
        $p = EmployeeChangeRequest::firstOrFail();
        $this->assertSame('delete', $p->children[0]['action']);

        $this->actingAs($this->hr())->post("/core-hr/change-requests/{$p->id}/verification", ['decision' => 'reject'])->assertSessionHasErrors('notes');
        $this->post("/core-hr/change-requests/{$p->id}/verification", ['decision' => 'reject', 'notes' => 'Lampirkan KK'])->assertSessionHasNoErrors();
        $this->assertSame(1, $k->profile->children()->count());

        // The employee sees the reason, sends again and withdraws it.
        $this->actingAs($user)->get('/self-service/profile')->assertInertia(fn (Assert $page) => $page->where('history.0.verification_notes', 'Lampirkan KK'));
        $this->post('/self-service/profile', ['ethnicity' => 'Jawa'])->assertSessionHasNoErrors();
        $second = EmployeeChangeRequest::latest('id')->firstOrFail();
        $this->delete("/self-service/profile/{$second->id}")->assertSessionHasNoErrors();
        $this->assertNull(EmployeeChangeRequest::find($second->id));
    }

    public function test_a_number_taken_since_is_refused_and_only_hr_with_the_menu_decides(): void
    {
        [$k, $user] = $this->employee();
        $this->actingAs($user)->post('/self-service/profile', ['national_id_number' => '3209011609820001'])->assertSessionHasNoErrors();
        $p = EmployeeChangeRequest::firstOrFail();

        // Someone else got that NIK meanwhile.
        Employee::withoutGlobalScope('access')->where('id', '!=', $k->id)->firstOrFail()->profile()->update(['national_id_number' => '3209011609820001']);
        $this->actingAs($this->hr())->post("/core-hr/change-requests/{$p->id}/verification", ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $p->fresh()->status);

        // An admin without the menu; the employee on the HR page.
        $viewer = User::where('email', 'viewer@eolas.local')->firstOrFail();
        $this->actingAs($viewer)->get('/core-hr/change-requests')->assertForbidden();
        $this->actingAs($user)->get('/core-hr/change-requests')->assertForbidden();
    }
}
