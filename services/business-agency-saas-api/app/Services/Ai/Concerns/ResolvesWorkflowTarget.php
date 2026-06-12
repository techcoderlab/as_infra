<?php

namespace App\Services\Ai\Concerns;

use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Trait ResolvesWorkflowTarget
 *
 * Provides a standardized method to resolve the target model from a WorkflowPayload.
 * Ensures consistent error logging and handling of soft-deleted models if necessary.
 */
trait ResolvesWorkflowTarget
{
    /**
     * Resolve the target model instance from the workflow payload.
     *
     * @param  bool  $withTrashed  Whether to include soft-deleted models in the search.
     * @return Model|null Returns the model instance or null if not found/invalid.
     */
    protected function resolveTarget(WorkflowPayload $payload, bool $withTrashed = false): ?Model
    {
        $modelClass = 'App\\Models\\'.$payload->targetType;

        // 1. Verify Class Exists
        if (! class_exists($modelClass)) {
            Log::error("[{$this->getHandlerName()}]: Model class {$modelClass} not found.", [
                'target_type' => $payload->targetType,
                'target_id' => $payload->targetId,
            ]);

            return null;
        }

        // 2. Resolve Model query
        $query = $modelClass::query();

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass))) {
            $query->withTrashed();
        }

        // 3. Find Model
        $target = $query->find($payload->targetId);

        if (! $target) {
            Log::error("[{$this->getHandlerName()}]: {$payload->targetType} #{$payload->targetId} not found.", [
                'target_type' => $payload->targetType,
                'target_id' => $payload->targetId,
            ]);

            return null;
        }

        return $target;
    }

    /**
     * Get the handler name for logging context.
     * Can be overridden by the consuming class.
     */
    protected function getHandlerName(): string
    {
        return class_basename($this);
    }
}
