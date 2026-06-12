<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\WorkflowPayload;

interface LlmProviderInterface
{
    /**
     * Process the agent workflow.
     *
     * * @param WorkflowPayload $payload The prepared data/context
     * @return array The standardized response ['response' => string, 'thoughtStream' => array]
     *
     * @throws \Exception If the provider fails
     */
    public function process(WorkflowPayload $payload, $promiseErrorCallback = null): array;

    /**
     * Check if this provider supports streaming.
     */
    public function supportsStreaming(): bool;
}
