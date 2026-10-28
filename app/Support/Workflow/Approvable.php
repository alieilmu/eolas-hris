<?php

namespace App\Support\Workflow;

use App\Models\WorkflowInstance;

/** A pengajuan that acts on the end of its flow (approved, rejected or cancelled). */
interface Approvable
{
    public function workflowCompleted(WorkflowInstance $instance): void;
}
