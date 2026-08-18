<?php

namespace App\Contracts\Events;

/**
 * Any event implementing this interface will automatically be routed
 * to the AgentTriggerListener.
 */
interface ShouldTriggerAgent
{
    /**
     * Return the target model instance (Lead, Form, Order, etc.)
     */
    public function getTargetModel();
}
