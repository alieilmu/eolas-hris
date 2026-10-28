<?php

namespace App\Models;

use App\Support\Audit\Audit;
use App\Support\Notifications\RequestNotifier;
use App\Support\Payroll\Loans;
use App\Support\Payroll\PayrollPeriods;
use App\Support\Workflow\Approvable;
use App\Support\Workflow\ChecksDecision;
use App\Support\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * An employee loan, repaid by monthly deductions from pay (loan_installments). Asked for in the
 * portal or entered by HR, it goes through its Workflow ("loan"); the last approval activates it.
 */
#[Fillable(['employee_id', 'principal', 'tenor', 'installment', 'start_period', 'purpose', 'status', 'source', 'notes', 'created_by', 'approved_by', 'approved_at', 'rejection_note'])]
class Loan extends Model implements Approvable, ChecksDecision
{
    public const STATUS = ['pending' => 'Menunggu persetujuan', 'active' => 'Berjalan', 'paid_off' => 'Lunas', 'rejected' => 'Ditolak'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $m) => Workflow::withdraw($m, 'Pinjaman dihapus.'));
    }

    public function workflowNotice(): array
    {
        return RequestNotifier::loanNotice($this);
    }

    /**
     * Not approved by whoever entered it (except the Super Admin); the last approval also needs
     * the installment within the limit and the first period's pay not approved yet.
     */
    public function workflowCheck(WorkflowInstance $instance, User $by, bool $final): void
    {
        if ($this->created_by === $by->id && ! $by->isAdmin()) {
            throw ValidationException::withMessages(['decision' => 'Pinjaman yang Anda catat disetujui oleh akun lain.']);
        }
        if (! $final) {
            return;
        }
        $employee = Employee::withoutGlobalScopes()->findOrFail($this->employee_id);
        Loans::assertAffordable($employee, $this->installment, $this);
        if (PayrollPeriods::closed($employee, $this->start_period)) {
            throw ValidationException::withMessages(['tenor' => 'Gaji periode mulai sudah disetujui. Ubah periode mulai ke periode berikutnya.']);
        }
    }

    /** Approved: the installments are scheduled; rejected: with the note; withdrawn by the employee: gone. */
    public function workflowCompleted(WorkflowInstance $instance): void
    {
        if ($instance->status === WorkflowInstance::CANCELLED) {
            $this->delete();

            return;
        }
        $last = Workflow::lastDecision($instance);
        $employee = Employee::withoutGlobalScopes()->find($this->employee_id);
        if ($instance->status === WorkflowInstance::APPROVED) {
            Loans::activate($this, (int) $last?->user_id);
            Audit::log('payroll.loan_approved', $this, "{$employee?->employee_number} {$employee?->name} · Rp ".number_format($this->principal, 0, ',', '.'));

            return;
        }
        $this->update(['status' => 'rejected', 'rejection_note' => $last?->notes]);
        Audit::log('payroll.loan_rejected', $this, $last?->notes);
    }

    /** What the step conditions of its flow look at: the amount. */
    public function workflowFacts(): array
    {
        return ['amount' => (int) $this->principal];
    }

    /** Puts it in its approval flow (by default whoever approves payroll at the employee's location). */
    public function startWorkflow(User $by): void
    {
        $notice = $this->workflowNotice();
        Workflow::start($this, 'loan', Employee::withoutGlobalScopes()->findOrFail($this->employee_id), $by, $this->workflowFacts(),
            'Pinjaman Rp '.number_format($this->principal, 0, ',', '.')." × {$this->tenor} bulan", $notice['url']);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function remaining(): int
    {
        return (int) $this->installments()->where('status', 'scheduled')->sum('amount');
    }
}
