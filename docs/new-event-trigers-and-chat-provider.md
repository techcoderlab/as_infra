# System Scaling (Laravel 11 Tailored) - Add New Chat Provider + New AI Event Triggers and Listeners

This document answers two critical architectural questions about the current state of your system, focusing purely on facts from your existing codebase and best-practice optimization strategies for scaling (DRY—Don't Repeat Yourself).

---

## 1. Adding a New Chat Provider (e.g., Telegram, Slack)

You already have a clean architecture using `MessagingProviderInterface` (`app/Contracts/Messaging/MessagingProviderInterface.php`) and `AbstractMessagingService` (`app/Services/Messaging/AbstractMessagingService.php`). Because `AbstractMessagingService` automatically fetches tenant credentials using `Integration::where('service', $this->getServiceKey())`, adding new providers is streamlined.

### What you need to add for a new provider (e.g., Telegram):

1. **New Ingress Controller**: 
   - A `TelegramIntegrationController.php` to receive the webhook from Telegram.
2. **New Webhook Job**:
   - A `ProcessTelegramWebhook.php` (similar to `ProcessWhatsAppWebhook.php`) to parse the JSON, create a `ChatMessage` bound to the lead's `AiChat`, create/link the `LeadChatSession` with `platform = 'telegram'`, and dispatch a `TelegramMessageReceived` event (which implements `ShouldTriggerAgent` — see Section 2).
3. **New Provider Service**:
   - Create `app/Services/TelegramServiceNative.php` extending `AbstractMessagingService`.
   - Implement `getServiceKey(): string { return 'telegram'; }`.
   - Implement `_sendRequest(array $payload)` to call Telegram's HTTP API.

### How to optimize `LeadMessageResultHandler` (DRY & Factory Pattern):

Currently, your `LeadMessageResultHandler.php` is hardcoded to instantiate WhatsApp:

```php
// Tightly coupled code (Current in LeadMessageResultHandler.php)
$waService = new \App\Services\WhatsAppServiceNative($target->tenant_id);
$waService->sendMessage($recipientPhone, $responseText);
```

### Update the Result Handler:
Modify `LeadMessageResultHandler.php` to dynamically resolve the provider based on the `LeadChatSession`:

```php
// Fully decoupled code (Optimized)
$session = \App\Models\LeadChatSession::where('lead_id', $target->getKey())->first();

if ($session) {
    // Dynamically resolves the correct provider using your Interface!
    $provider = \App\Services\Messaging\MessagingServiceFactory::make($session->platform, $target->tenant_id);
    
    // Send standard text
    $provider->sendMessage($recipientPhone, $responseText);
}
```

---

## 2. Adding New Events, Triggers, and Listeners (Laravel 11 Architecture)

In **Laravel 11**, the traditional `EventServiceProvider`, `RouteServiceProvider`, and `AuthServiceProvider` were removed to streamline the framework into a single `AppServiceProvider.php`.

Your AI engine is already built dynamically: `AgentTriggerListener` queries the `agent_triggers` table dynamically using `get_class($event)` and attaches to any target model (`Lead`, `FormSubmission`, etc.) polymorphically via `target_id` and `target_type`.

### The Setup (One-Time — Already Done)

The manual `Event::listen(...)` registration in `AppServiceProvider.php` has been replaced with **Contract-based Event Auto-Discovery**, built on two pieces you have already created and registered:

1. **`App\Contracts\Events\ShouldTriggerAgent`** — a marker interface whose implementing events expose `getTargetModel()`.
2. **`App\Listeners\AgentEventSubscriber`** — an event subscriber that auto-discovers all events implementing the interface and binds them to the `AgentTriggerListener`.

The only registration needed (already in place) is the single line in `boot()`:

```php
// AppServiceProvider.php (already registered)
Event::subscribe(AgentEventSubscriber::class);
```

### Usage: Adding a New Triggering Event

Because of the auto-discovery setup, adding a new event (e.g., `AppointmentBooked`) requires **no registration at all**. Simply implement the interface:

```php
<?php

namespace App\Events;

use App\Contracts\Events\ShouldTriggerAgent;
use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentBooked implements ShouldTriggerAgent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Lead $model) {}

    public function getTargetModel()
    {
        return $this->model;
    }
}
```

That's it. The moment this class exists in `app/Events`, it is picked up automatically on the next boot — no edits to `AppServiceProvider.php`, no new listener classes, no deployments for wiring.

### How It Works Behind the Scenes

1. **Boot**: On every request/worker boot, `Event::subscribe(AgentEventSubscriber::class)` invokes the subscriber's `subscribe(Dispatcher $events)` method.
2. **Scan**: The subscriber uses Symfony's `Finder` to iterate over every `*.php` file in `app/Events` and converts each relative path into a fully-qualified class name (e.g., `Events/Appointments/OrderPlaced.php` → `App\Events\Appointments\OrderPlaced`).
3. **Reflect**: For each class, `ReflectionClass::implementsInterface(ShouldTriggerAgent::class)` checks whether the event opted into AI triggering. Only matching classes are bound.
4. **Bind**: Matching events are registered via `$events->listen($class, [self::class, 'handleAgentTrigger'])`.
5. **Forward**: When the event fires at runtime, `handleAgentTrigger` simply delegates to `app(AgentTriggerListener::class)->handle($event)`.
6. **Dispatch to AI**: `AgentTriggerListener` then looks up `agent_triggers` by `get_class($event)`, uses `getTargetModel()` to resolve the polymorphic target, and hands off to `AiGateway` as usual.

```
Event class created → implements ShouldTriggerAgent
        │
        ▼ (boot: Finder scan + Reflection check)
AgentEventSubscriber binds it → handleAgentTrigger
        │
        ▼ (runtime: event fired)
AgentTriggerListener → agent_triggers lookup → AiGateway
```

### The Benefit:
- **Zero Config Maintenance**: Any time a new event (e.g. `AppointmentBooked`, `OrderPlaced`, `CustomFormSubmitted`) is created with `implements ShouldTriggerAgent`, it is automatically detected and registered on boot.
- **Full Database Control**: Non-technical admins or tenant configurations can activate/deactivate agents for that event by simply toggling records in the `agent_triggers` table without touching code or deploying.
