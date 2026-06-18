<?php

use App\Http\Controllers\Admin\AiAgentController;
use App\Http\Controllers\Admin\AiIntegrationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\GoogleBusinessController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PublicFormController; // Import
use App\Http\Controllers\Service\WhatsAppIntegrationController;
use App\Http\Controllers\Sidecar\AiJobController;
use App\Http\Controllers\Sidecar\AiWebhookController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\WebhookController;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum', 'throttle:tenant_api', 'check.status', 'log.api', 'plan.expiry', 'check.tenant_access'])->group(function () {
    Route::get('/bootstrap', BootstrapController::class);
    // Route::post('/n8n/token', [AuthController::class, 'n8nToken']);

    // Super admin management
    Route::get('/ai-settings', [AiIntegrationController::class, 'getSettings']);
    Route::post('/ai-settings', [AiIntegrationController::class, 'updateSettings']);

    // External API Keys Management (Super Admin)
    Route::get('/external-api-keys', [\App\Http\Controllers\Admin\ExternalApiKeyController::class, 'index']);
    Route::post('/external-api-keys', [\App\Http\Controllers\Admin\ExternalApiKeyController::class, 'store']);
    Route::delete('/external-api-keys/{id}', [\App\Http\Controllers\Admin\ExternalApiKeyController::class, 'destroy']);
    Route::post('/external-api-keys/{id}/rotate', [\App\Http\Controllers\Admin\ExternalApiKeyController::class, 'rotate']);

    // Tenant Integration Management
    Route::get('/integrations/services', [IntegrationController::class, 'availableServices']);

    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::post('/integrations', [IntegrationController::class, 'store']);
    Route::put('/integrations/{id}', [IntegrationController::class, 'update']);
    Route::delete('/integrations/{id}', [IntegrationController::class, 'destroy']);

    Route::get('/integrations/available', [AiAgentController::class, 'availableIntegrations']);
    Route::apiResource('ai-agents', AiAgentController::class);

    // Route::get('/ai-agent/stats', [AiAgentController::class, 'stats']);
    // Route::post('/ai-agent/settings', [AiAgentController::class, 'updateSettings']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
    Route::post('/tenants/crm-config', [TenantController::class, 'updateCrmConfig']);
    Route::get('/tenants/modules', [TenantController::class, 'getModulesForTenant']);

    Route::get('/google-business/connect', [GoogleBusinessController::class, 'connect']);
    Route::post('/leads/{lead}/post-review-reply', [GoogleBusinessController::class, 'postReviewReply']);

    Route::get('/plans-data', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'storePlan']);
    Route::put('/plans/{plan}', [PlanController::class, 'updatePlan']);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroyPlan']);
    Route::post('/modules', [PlanController::class, 'storeModule']);
    Route::put('/modules/{module}', [PlanController::class, 'updateModule']);
    // Assign Plan
    Route::post('/tenants/{tenant}/assign-plan', [PlanController::class, 'assignPlan']);

    // Super Admin User Management
    Route::get('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'index']);
    Route::post('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'store']);
    Route::get('/admin/users/{user}', [\App\Http\Controllers\Admin\UserManagementController::class, 'show']);
    Route::put('/admin/users/{user}', [\App\Http\Controllers\Admin\UserManagementController::class, 'update']);
    Route::delete('/admin/users/{user}', [\App\Http\Controllers\Admin\UserManagementController::class, 'destroy']);
    Route::post('/admin/users/{user}/assign-tenant', [\App\Http\Controllers\Admin\UserManagementController::class, 'assignTenant']);
    Route::delete('/admin/users/{user}/tenants/{tenant}', [\App\Http\Controllers\Admin\UserManagementController::class, 'removeTenant']);
    Route::put('/admin/users/{user}/tenants/{tenant}/role', [\App\Http\Controllers\Admin\UserManagementController::class, 'updateTenantRole']);


    // AI Chat Modules
    Route::middleware(['check.module:ai_chats'])->group(function () {
        Route::apiResource('ai-chats', AiChatController::class);
        Route::get('/ai-chats/{aiChat}/history', [\App\Http\Controllers\AiChatController::class, 'history']);
        Route::get('ai-chats/{aiChat}/status', [AiChatController::class, 'checkConnection']);
        // Route::post('/ai-chats/{aiChat}/chat', [AiChatController::class, 'chat']);

        Route::post('/ai-chats/{aiChat}/message', [AiChatController::class, 'storeMessage']);
        // Route::get('/ai-chats/{aiChat}/chat-stream', [AiChatController::class, 'chatStream'])
        //     ->name('ai.chat.chat_stream');
    });

    // Agency forms CRUD (per-tenant via global scope)
    Route::middleware(['check.module:forms'])->group(function () {
        Route::get('/forms', [FormController::class, 'index']);
        Route::post('/forms', [FormController::class, 'store']);
        Route::put('/forms/{form}', [FormController::class, 'update']);
        Route::delete('/forms/{form}', [FormController::class, 'destroy']);
    });

    // Webhooks Management
    Route::middleware(['check.module:webhooks'])->group(function () {
        // Route::get('/webhooks', [WebhookController::class, 'index']);
        // Route::post('/webhooks', [WebhookController::class, 'store']);
        // Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
        Route::apiResource('webhooks', WebhookController::class);
    });

    // --- Leads Management Module ---
    // This section handles all operations related to Leads.
    // Ensure the 'leads' module is enabled for the tenant.
    Route::middleware(['check.module:leads'])->group(function () {

        // Get dashboard statistics and metrics
        Route::get('/leads/stats', [LeadController::class, 'stats']);

        // List leads with filters and pagination
        Route::get('/leads', [LeadController::class, 'index']);

        // View details for a specific lead
        Route::get('/leads/{lead}', [LeadController::class, 'show']);

        // Get activity history for a specific lead
        Route::get('/leads/{lead}/activities', [LeadController::class, 'activities']);

        // Add a manual or system note to a lead's activity history
        Route::post('/leads/{lead}/note', [LeadController::class, 'addNote']);

        // Create a single lead
        Route::post('/leads', [LeadController::class, 'store']);

        // Create multiple leads efficiently in one request
        Route::post('/leads/batch', [LeadController::class, 'batchStore']);

        // Update lead status, temperature, or notes
        Route::put('/leads/{lead}', [LeadController::class, 'update']);

        // Import leads from a CSV file upload
        Route::post('/leads/import', [LeadController::class, 'import']);

        // Export selected or all leads to a CSV stream
        Route::post('/leads/export', [LeadController::class, 'export']);
    });

    // API Keys Management (New)
    Route::middleware(['check.module:api_keys'])->group(function () {
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::put('/api-keys/{id}', [ApiKeyController::class, 'update']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
        Route::post('/api-keys/{id}/rotate', [ApiKeyController::class, 'rotate']);
    });

    /* Routes for monitoring AI Jobs */
    Route::get('/ai-jobs/{target_id}/monitor', [AiJobController::class, 'monitor']);
});

// Wrap public routes in the throttle middleware
Route::prefix('public')->group(function () {
    Route::get('/form/{uuid}', [PublicFormController::class, 'show']);
    Route::post('/form/{uuid}/submit', [PublicFormController::class, 'submit'])->middleware('throttle:10,1', 'tracker.validate');
    Route::post('/external/form/submit', [PublicFormController::class, 'thirdPartyFormSubmit'])->middleware('throttle:10,1', 'tracker.validate');
    Route::post('/tally/form/submit', [PublicFormController::class, 'tallyFormSubmit'])->middleware('throttle:10,1', 'tracker.validate');
});

Route::middleware([\App\Http\Middleware\VerifyExternalAppSignature::class])->group(function () {
    Route::post('/mcp/callback/ai-result', [AiWebhookController::class, 'handle'])->name('api.mcp.callback.ai');
});

// WhatsApp Webhook (Public)
Route::get('/integrations/whatsapp/webhook/{tenant}', [WhatsAppIntegrationController::class, 'verify']);
Route::post('/integrations/whatsapp/webhook/{tenant}', [WhatsAppIntegrationController::class, 'receive']);

Route::get('/ai-chats/{user}/{aiChat}/chat-stream', [AiChatController::class, 'chatStream'])
    ->middleware(['signed'])
    ->name('ai.chat.stream');

Route::get('/google-business/callback', [GoogleBusinessController::class, 'callback'])->name('google-business.callback');

// Route::get('/ai-jobs/{target_id}/monitor', [AiJobController::class, 'monitor']);

Route::middleware([\App\Http\Middleware\VerifyExternalAppSignature::class])->group(function () {
    Route::post('/internal/leads/{lead}/update', function (Request $request, $leadId) {

        // 1. Auth & Tenant Guard (Unchanged)
        $tenantId = $request->header('x-tenant-id');
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context missing'], 403);
        }

        try {
            app(\App\Services\TenantManager::class)->setTenantById((int) $tenantId);

            // PRE-FILTER: Avoid DB overhead if no data is sent
            $dataToUpdate = array_filter(
                $request->only(['temperature', 'status', 'score', 'won', 'payload']),
                fn ($value) => ! is_null($value)
            );

            Log::info("MCP Update: Updating lead #{$leadId} with data: ".json_encode($dataToUpdate));

            if (empty($dataToUpdate)) {
                return response()->json(['success' => true]);
            }

            // ATOMIC EXECUTION
            DB::transaction(function () use ($leadId, $dataToUpdate) {
                // LOCK FOR UPDATE: Prevents other requests from touching this lead until finished
                $lead = \App\Models\Lead::where('id', $leadId)
                    ->lockForUpdate()
                    ->first();

                if (! $lead) {
                    throw new ModelNotFoundException("Lead #{$leadId} not found.");
                }

                $activities = [];
                $finalUpdateData = []; // Use this to be 100% sure what we are updating

                // 1. Handle Basic Fields
                $basicFields = ['temperature' => 'temperature', 'status' => 'pipeline', 'score' => 'score', 'won' => 'won'];
                // $basicUpdate = array_intersect_key($dataToUpdate, $basicFields);

                foreach ($basicFields as $dbKey => $label) {

                    if (! isset($dataToUpdate[$dbKey])) {
                        continue;
                    }

                    $newVal = $dataToUpdate[$dbKey];
                    $oldVal = $lead->$dbKey;

                    // String-based comparison to handle all types
                    if (strtolower((string) $newVal) === strtolower((string) $oldVal)) {
                        continue;
                    }

                    $finalUpdateData[$dbKey] = $newVal;

                    $displayVal = is_bool($newVal) ? ($newVal ? 'Yes' : 'No') : ucwords(str_replace(['_', '-'], ' ', (string) $newVal));

                    $activities[] = [
                        'lead_id' => $lead->getKey(),
                        'type' => 'mcp_updated',
                        'content' => 'AI updated '.ucwords($label)." to: {$displayVal}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // 2. Handle Payload Merging
                if (isset($dataToUpdate['payload']) && is_array($dataToUpdate['payload'])) {

                    // Ensure $currentPayload is always a clean array, regardless of DB cast settings
                    $currentPayload = $lead->payload;
                    if (is_string($currentPayload)) {
                        $currentPayload = json_decode($currentPayload, true) ?: [];
                    }
                    $currentPayload = is_array($currentPayload) ? $currentPayload : [];

                    foreach ($dataToUpdate['payload'] as $subKey => $subVal) {

                        // 1. Robust Equality Check (Handles string vs int vs bool comparison)
                        $existingVal = $currentPayload[$subKey] ?? null;

                        // Use loose comparison but check for nulls to avoid "0 == null" false positives
                        if ($existingVal !== null && strtolower((string) $existingVal) === strtolower((string) $subVal)) {
                            continue;
                        }

                        // 2. Data Cleaning
                        $cleanKey = strip_tags(str_replace(['_', '-'], ' ', $subKey));
                        $displayVal = is_bool($subVal) ? ($subVal ? 'Yes' : 'No') : (string) $subVal;

                        // 3. Update the payload
                        $currentPayload[$subKey] = $subVal;

                        // 4. Activity Logging with Guard
                        // if ($lead->getKey()) {
                        $activities[] = [
                            'lead_id' => $lead->getKey(),
                            'type' => 'mcp_updated',
                            'content' => 'Collected '.ucwords($cleanKey).': '.$displayVal,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        // }
                    }

                    // Assign the merged array back
                    $finalUpdateData['payload'] = $currentPayload;
                }

                // 5. Atomic Update only with verified data
                if (! empty($finalUpdateData)) {
                    $lead->updateQuietly($finalUpdateData);
                }

                // 6. Fail-safe Bulk Insert
                if (! empty($activities)) {
                    \App\Models\LeadActivity::insert($activities);
                }
            });

            return response()->json(['success' => true]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            \Log::error('MCP Update Error: '.$e->getMessage());

            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    });

    Route::post('/internal/leads/search', function (Request $request) {
        // 1. Fast Security Exit
        $tenantId = $request->header('x-tenant-id');
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context missing'], 403);
        }

        try {
            // 2. Set Tenant Context
            app(\App\Services\TenantManager::class)->setTenantById((int) $tenantId);

            $limit = $request->integer('limit', 5); // Use integer() for safer casting
            $leadsQuery = \App\Models\Lead::query();

            // 3. Robust Date Filtering
            if ($request->filled('date_filter')) {
                switch ($request->input('date_filter')) {
                    case 'today':
                        $leadsQuery->whereDate('created_at', Carbon::today());
                        break;
                    case 'this_week':
                        $leadsQuery->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                        break;
                    case 'this_month':
                        $leadsQuery->whereMonth('created_at', Carbon::now()->month)
                            ->whereYear('created_at', Carbon::now()->year);
                        break;
                    case 'ytd':
                        $leadsQuery->whereBetween('created_at', [Carbon::now()->startOfYear(), Carbon::now()]);
                        break;
                    case 'custom':
                        if ($request->filled(['start_date', 'end_date'])) {
                            $leadsQuery->whereBetween('created_at', [
                                Carbon::parse($request->input('start_date'))->startOfDay(),
                                Carbon::parse($request->input('end_date'))->endOfDay(),
                            ]);
                        }
                        break;
                }
            }

            // 4. Text Search
            if ($request->filled('query')) {
                $searchTerm = "%{$request->input('query')}%";
                $leadsQuery->where(function (Builder $q) use ($searchTerm) {
                    $q->where('id', 'like', $searchTerm)
                        ->orWhere('notes', 'like', $searchTerm)
                        ->orWhere('status', 'like', $searchTerm)
                        ->orWhere('temperature', 'like', $searchTerm)
                        ->orWhere('source', 'like', $searchTerm)
                        ->orWhereRaw('CAST(payload AS TEXT) ILIKE ?', [$searchTerm]);
                });
            }

            // 5. Optimization: Get Count and Results in a way that minimizes DB load
            // Use a clone to ensure count doesn't interfere with the limit/offset of the main query
            $totalCount = (clone $leadsQuery)->count();

            $leads = $leadsQuery->orderBy('created_at', 'desc') // Explicit column name for speed
                ->limit($limit)
                ->get();

            // 6. Memory Efficient Mapping
            $results = $leads->map(function ($lead) {
                // We strip any existing relations to keep the payload tiny
                $payload = is_array($lead->payload) ? $lead->payload : json_decode($lead->payload, true) ?? [];

                return array_merge([
                    'id' => $lead->id,
                    'created_at' => $lead->created_at->diffForHumans(), // AI prefers relative time (e.g. "2 hours ago")
                    'temperature' => $lead->temperature,
                    'status' => $lead->status,
                    'source' => $lead->source,
                ], $payload);
            });

            return response()->json([
                'count' => $totalCount,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            \Log::error('MCP Search Error: '.$e->getMessage());

            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    });
});
