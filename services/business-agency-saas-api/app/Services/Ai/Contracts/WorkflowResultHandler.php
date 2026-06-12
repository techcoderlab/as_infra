<?php

namespace App\Services\Ai\Contracts;

use App\Models\Tenant;
use App\Services\Ai\DTO\WorkflowPayload;

interface WorkflowResultHandler
{
    /**
     * Handle the successful result of an AI workflow.
     *
     * @param  WorkflowPayload  $payload  The original request context
     * @param  array  $result  Contains ['response' => string, 'thoughtStream' => array]
     */
    public function handle(Tenant $tenant, WorkflowPayload $payload, array $result): void;
}
