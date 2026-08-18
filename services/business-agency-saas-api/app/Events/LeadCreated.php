<?php

namespace App\Events;

use App\Models\Lead;
use App\Contracts\Events\ShouldTriggerAgent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadCreated implements ShouldTriggerAgent
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Lead $model) {}

    public function getTargetModel(): Lead
    {
        return $this->model;
    }
}
