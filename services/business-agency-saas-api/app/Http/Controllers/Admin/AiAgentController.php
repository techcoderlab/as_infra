<?php

// ─────────────────────────────────────────────────────
// Module   : AiAgentController
// ─────────────────────────────────────────────────────

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Integration;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiAgentController extends Controller
{
    public $tenantManager = null;

    public function __construct()
    {
        $this->tenantManager = app(TenantManager::class);
    }

    /**
     * List all agents for the current tenant.
     */
    public function index(Request $request)
    {
        try {
            $this->authorize('viewAny', AiAgent::class);

            // Initialize variables with defaults to prevent compact() errors
            $agents = collect();
            $availableTriggers = [];
            $availableAiTools = [];

            $tenantId = $this->tenantManager->getActiveTenant()->id;

            $agents = AiAgent::with('trigger')
                ->where('tenant_id', $tenantId)
                ->orderByRaw('created_at DESC NULLS LAST')
                ->get();

            foreach ($agents as $agent) {
                try {
                    $agent->toArray(); // This triggers the decryption
                } catch (\Exception $e) {
                    return response()->json(['error_at_id' => $agent->id, 'msg' => $e->__toString()]);
                }
            }

            $availableTriggers = $this->getAvailableTriggers();
            $availableAiTools = $this->getAvailableAiTools();

            return response()->json([
                'agents' => $agents,
                'availableTriggers' => $availableTriggers,
                'availableAiTools' => $availableAiTools,
            ]);
        } catch (\Throwable $th) {
            // Log the actual error for debugging
            \Log::error($th->getMessage());

            return response()->json([
                'error' => 'An error occurred while fetching agents.',
                'details' => $th->getMessage(),
            ], 400);
        }
    }

    private function getAvailableAiTools()
    {
        // Use Cache::remember to avoid re-scanning the disk on every request
        Cache::forget('app_ai_tools_list');

        return Cache::remember('app_ai_tools_list', 30, function () {
            return config('ai_tools');
        });
    }

    private function getAvailableTriggers()
    {
        // Use Cache::remember to avoid re-scanning the disk on every request
        return Cache::remember('app_events_list', 30, function () {
            $eventsPath = app_path('Events');

            // Robust check: Ensure the directory exists before scanning
            if (! File::isDirectory($eventsPath)) {
                return [];
            }

            // Get all files recursively to support sub-directories
            return collect(File::allFiles($eventsPath))
                ->filter(fn ($file) => $file->getExtension() === 'php')
                ->map(function ($file) {
                    // Determine the Fully Qualified Class Name (FQCN)
                    // e.g., /path/to/app/Events/LeadCreated.php -> App\Events\LeadCreated
                    $relativePath = $file->getRelativePathname();
                    $className = 'App\\Events\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

                    return [
                        'id' => $className,
                        // headline() turns "TicketCreated" into "Ticket Created"
                        'label' => Str::headline($file->getBasename('.php')),
                    ];
                })
                ->values()
                ->toArray();
        });
    }

    /**
     * Store a new agent.
     */
    public function store(Request $request)
    {
        $this->authorize('create', AiAgent::class);

        $tenantId = $this->tenantManager->getActiveTenant()->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Ensure slug is unique per tenant
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('ai_agents')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'brain' => 'required|string', // The service key (e.g. 'openai')
            'model' => 'required|string', // e.g. 'gpt-4'
            'system_prompt' => 'nullable|string',
            'user_prompt' => 'nullable|string',
            'tools' => 'nullable|array',
            'triggers' => 'nullable|array', // Logic to save triggers handled below or via relationship
            'is_active' => 'boolean',
        ]);

        $agent = new AiAgent($validated);
        $agent->tenant_id = $tenantId;
        $agent->fill($validated);      // This triggers the mutator with the ID present
        $agent->save();

        // $agent = AiAgent::create(array_merge($validated, ['tenant_id' => $tenantId]));

        // Handle Triggers (if sent as an array of event class strings)
        if ($request->has('triggers')) {
            $this->syncTriggers($agent, $request->triggers);
        }

        return response()->json($agent->fresh(['trigger']), 201);
    }

    /**
     * Update an existing agent.
     */
    public function update(Request $request, string $id)
    {

        $tenantId = $this->tenantManager->getActiveTenant()->id;

        $agent = AiAgent::where('tenant_id', $tenantId)->find($id);

        if (! $agent) {
            return response()->json(['message' => 'Agent not found'], 404);
        }

        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('ai_agents')->ignore($agent->id)->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'brain' => 'sometimes|string',
            'model' => 'sometimes|string',
            'system_prompt' => 'nullable|string',
            'user_prompt' => 'nullable|string',
            'tools' => 'nullable|array',
            'triggers' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $originalSlug = $agent->slug; // Capture for cache clearing

        $agent->update($validated);

        if ($request->has('triggers')) {
            $this->syncTriggers($agent, $request->triggers);
        }

        // Clear Cache (Critical for instant updates)
        Cache::forget("ai_agent:{$tenantId}:{$originalSlug}");
        if ($originalSlug !== $agent->slug) {
            Cache::forget("ai_agent:{$tenantId}:{$agent->slug}");
        }

        // Refresh to include any updated relationships/attributes
        return response()->json($agent->fresh(['trigger']));
    }

    /**
     * Delete an agent.
     */
    public function destroy(Request $request, string $id)
    {
        $tenantId = $this->tenantManager->getActiveTenant()->id;

        $agent = AiAgent::where('tenant_id', $tenantId)->find($id);

        if (! $agent) {
            return response()->json(['message' => 'Agent not found'], 404);
        }

        $this->authorize('delete', $agent);

        // Optional: Detach triggers or relying on cascade delete
        $agent->delete();

        // Clear Cache
        Cache::forget("ai_agent:{$tenantId}:{$agent->slug}");

        return response()->json(['message' => 'Agent deleted successfully']);
    }

    /**
     * Return active "Brain" integrations (LLMs) for the dropdown.
     * Route: GET /api/integrations/available (Mapped here)
     */
    public function availableIntegrations(Request $request)
    {

        $this->authorize('viewAny', AiAgent::class);

        $tenantId = $this->tenantManager->getActiveTenant()->id;

        // whitelist of services that can act as "Brains"
        // $llmProviders = ['openai', 'anthropic', 'gemini', 'mistral', 'azure_openai'];

        $integrations = Integration::where('tenant_id', $tenantId)
            // ->whereIn('service', $llmProviders)
            ->where('is_brain', true)
            ->where('is_active', true)
            ->select(['id', 'service', 'tenant_id', 'created_at']) // Don't return secrets (value column)
            ->get()
            ->map(function ($integration) {
                return [
                    'id' => $integration->id,
                    'service' => $integration->service,
                    'name' => ucfirst($integration->service), // Formatting for UI
                ];
            });

        return response()->json($integrations);
    }

    /**
     * Helper to sync triggers to the dedicated table.
     */
    protected function syncTriggers(AiAgent $agent, array $eventClasses)
    {
        // Remove old triggers for this agent
        \App\Models\AgentTrigger::where('ai_agent_id', $agent->id)->delete();

        // Add new ones
        foreach ($eventClasses as $eventClass) {
            \App\Models\AgentTrigger::create([
                'tenant_id' => $agent->tenant_id,
                'ai_agent_id' => $agent->id,
                'event_class' => $eventClass,
                'is_active' => true,
            ]);
        }
    }

    // public function stats(Request $request)
    // {

    //     $this->authorize('viewAny', AiAgent::class);

    //     /** @var Tenant $tenant */
    //     $tenant = $this->tenantManager->getActiveTenant();

    //     $planLimit = $tenant->plans->first()?->ai_credit_limit ?? 0;

    //     // 2. Plan Limits (Check plan_tenant pivot)
    //     $subscription = DB::table('plan_tenant')
    //         ->where('tenant_id', $tenant->id)
    //         // ->where('is_active', true) // Assuming you track active status
    //         ->first();

    //     $used = $subscription ? $subscription->ai_credits_used : 0;

    //     return response()->json([
    //         'used' => $used,
    //         'limit' => $planLimit,
    //         'percentage' => $planLimit > 0 ? round(($used / $planLimit) * 100) : 0,
    //         'settings' => $tenant->settings()->first()?->only(['ai_provider_default']),
    //     ]);
    // }

    // public function updateSettings(Request $request)
    // {

    //     $this->authorize('viewAny', AiAgent::class);

    //     $request->validate([
    //         'ai_provider_default' => 'required|in:openai,gemini,claude',
    //     ]);

    //     /** @var Tenant $tenant */
    //     $tenant = $this->tenantManager->getActiveTenant();

    //     // Save Provider Preference
    //     $tenant->settings()->update(
    //         ['ai_provider_default' => $request->ai_provider_default]
    //     );

    //     return response()->json(['message' => 'AI Settings Updated']);
    // }
}
