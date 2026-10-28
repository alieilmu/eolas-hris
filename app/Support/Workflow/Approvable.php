<?php

namespace App\Support\Workflow;

use App\Models\WorkflowInstance;

/** A pengajuan that goes through a Workflow and acts on its end (approved, rejected or cancelled). */
interface Approvable
{
    /**
     * The notification its approvers get, as the module sent it before Workflow, so the default
     * flow (HR) notifies exactly as it did. HR steps link to `url`; others to Persetujuan Saya.
     *
     * @return array{type: string, ref: string, title: string, body: string, url: string}
     */
    public function workflowNotice(): array;

    public function workflowCompleted(WorkflowInstance $instance): void;
}
