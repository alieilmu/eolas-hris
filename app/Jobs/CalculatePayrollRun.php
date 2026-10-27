<?php

namespace App\Jobs;

use App\Models\PayrollRun;
use App\Support\Employees\EmployeeNumber;
use App\Support\Employees\LookupValues;
use App\Support\Payroll\PayrollRunner;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Works out a payroll run on the queue, in the workspace it was started from (the queue lives
 * in the main database). Caches kept in memory are cleared first, the worker serving every
 * workspace in turn.
 */
class CalculatePayrollRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public ?string $database;

    public function __construct(public int $runId, public ?int $userId = null)
    {
        $this->database = Tenant::current();
    }

    public function handle(): void
    {
        $this->within(function () {
            $run = PayrollRun::find($this->runId);
            if ($run) {
                PayrollRunner::calculate($run, $this->userId);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->within(fn () => PayrollRun::whereKey($this->runId)->where('status', 'calculating')
            ->update(['status' => 'draft', 'rejection_note' => 'Perhitungan gagal: '.mb_substr((string) $e?->getMessage(), 0, 400)]));
    }

    private function within(callable $work): void
    {
        $switch = $this->database !== null && Tenant::current() !== $this->database;
        if ($switch) {
            Tenant::use($this->database);
        }
        LookupValues::flush();
        EmployeeNumber::flush();
        try {
            $work();
        } finally {
            if ($switch) {
                Tenant::central();
            }
        }
    }
}
