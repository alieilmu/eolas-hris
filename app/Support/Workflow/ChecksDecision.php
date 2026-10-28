<?php

namespace App\Support\Workflow;

use App\Models\User;
use App\Models\WorkflowInstance;

/**
 * A pengajuan with rules of its own an approval must meet (a loan's installment still affordable,
 * a claim still inside its plafon): checked before every approval, and $final when it is the
 * one that ends the flow. Throws a ValidationException to stop it.
 */
interface ChecksDecision
{
    public function workflowCheck(WorkflowInstance $instance, User $by, bool $final): void;
}
