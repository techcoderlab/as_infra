<?php

namespace App\Listeners;

use App\Contracts\Events\ShouldTriggerAgent;
use Illuminate\Events\Dispatcher;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class AgentEventSubscriber
{
    /**
     * Forward the event to the AgentTriggerListener handler.
     */
    public function handleAgentTrigger(object $event): void
    {
        app(AgentTriggerListener::class)->handle($event);
    }

    /**
     * Auto-discover all Events implementing ShouldTriggerAgent and register them.
     */
    public function subscribe(Dispatcher $events): void
    {
        $eventsPath = app_path('Events');

        if (!is_dir($eventsPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($eventsPath)->name('*.php');

        foreach ($finder as $file) {
            $class = 'App\\Events\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);

                if ($reflection->implementsInterface(ShouldTriggerAgent::class)) {
                    $events->listen($class, [self::class, 'handleAgentTrigger']);
                }
            }
        }
    }
}
