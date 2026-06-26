<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchStoreLeadRequest;
use App\Http\Requests\ExportLeadRequest;
use App\Http\Requests\ImportLeadRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Models\Lead;
use App\Models\TenantSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

/**
 * @group Lead Management
 *
 * API Endpoints for managing leads, including creation, retrieval, updates, and exports.
 * All endpoints are scoped to the authenticated user's current tenant.
 */
class LeadController extends Controller
{
    private function getCrmConfig($tenantId)
    {
        // 3-Layer Check via Policy
        $this->authorize('viewAny', Lead::class);

        $settings = TenantSetting::where('tenant_id', $tenantId)->first();

        return $settings->crm_config ?? [
            'entity_name_singular' => 'Lead',
            'entity_name_plural' => 'Leads',
            'statuses' => [
                ['slug' => 'new', 'label' => 'New', 'color' => 'blue'],
                ['slug' => 'contacted', 'label' => 'Contacted', 'color' => 'yellow'],
                ['slug' => 'closed', 'label' => 'Closed', 'color' => 'green'],
            ],
        ];
    }

    /**
     * Get Lead Statistics
     *
     *
     * Get key metrics for the dashboard, including totals, conversion rates, and growth trends.
     * Use this to populate dashboard widgets and sparkline charts.
     *
     * @authenticated
     *
     * @responseFile storage/responses/leads/stats.json
     *
     * @response 401 { "message": "Unauthenticated." }
     */
    public function stats(Request $request)
    {

        // 3-Layer Check via Policy
        $this->authorize('viewAny', Lead::class);
        $tenantId = $request->user()->current_tenant_id;

        $cacheKey = "dashboard_stats_{$tenantId}";
        $data = Cache::get($cacheKey);
        
        // Background cache refresh logic
        $lastUpdated = Cache::get("{$cacheKey}_last_updated", 0);
        if (time() - $lastUpdated > 120) {
            \App\Jobs\CalculateTenantStatsJob::dispatch($tenantId);
            // Bump the timestamp so we don't dispatch it multiple times while it's processing
            Cache::put("{$cacheKey}_last_updated", time(), 300);
        }

        if (!$data) {
            // Default empty state while generating
            $data = [
                'overview' => ['total_leads' => 0, 'new_leads' => 0, 'hot_leads' => 0, 'conversion_rate' => 0, 'stale_leads' => 0],
                'growth' => ['this_month' => 0, 'last_month' => 0, 'percentage' => 0],
                'chart_data' => [],
                'top_sources' => [],
                'leads_search_filters' => ['temperatures' => [], 'sources' => []],
            ];
        }

        return response()->json([
            'stats' => $data,
            'config' => $this->getCrmConfig($tenantId),
        ]);
    }

    /**
     * Get Lead Activities Log
     *
     * Retrieve the most recent 20 activity logs for a specific lead.
     *
     * @authenticated
     *
     * @urlParam lead_id integer required The ID of the lead. Example: 1
     *
     * @response [
     *   {
     *     "id": 1,
     *     "type": "note_added",
     *     "content": "Customer requested a callback.",
     *     "created_at": "2026-01-25 12:00:00"
     *   }
     * ]
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 404 { "message": "Lead not found." }
     */
    public function activities(Lead $lead)
    {
        // Ensure the lead belongs to the user's tenant
        $this->authorize('view', $lead);

        $activities = $lead->activities()
            ->latest()
            ->paginate(20, ['id', 'type', 'content', 'created_at']);

        return response()->json($activities);
    }

    /**
     * List Leads
     *
     * Retrieve a paginated list of leads with optional filtering and search.
     *
     * @authenticated
     *
     * @queryParam status string Filter by lead status. Example: new
     * @queryParam temperature string Filter by temperature (cold, warm, hot). Example: hot
     * @queryParam source string Filter by lead source. Example: Facebook Ads
     * @queryParam date_from string Filter leads created after this date (Y-M-D). Example: 2026-01-01
     * @queryParam date_to string Filter leads created before this date (Y-M-D). Example: 2026-01-31
     * @queryParam search string Search by ID, source, notes, or payload content. Example: John Doe
     * @queryParam per_page integer Number of leads per page (5-100). Default: 20. Example: 20
     *
     * @responseFile storage/responses/leads/index.json
     *
     * @response 401 { "message": "Unauthenticated." }
     */
    public function index(Request $request)
    {
        // 1. Authorization
        $this->authorize('viewAny', Lead::class);

        // 2. Start with a lean query, scoped to the tenant
        $query = Lead::query()->where('tenant_id', $request->user()->current_tenant_id);

        // 1. Normalize Inputs (Handle both 'date_from' and 'start_date')
        $start = $request->input('date_from') ?? $request->input('start_date');
        $end = $request->input('date_to') ?? $request->input('end_date');

        // 2. Apply Filter if dates exist
        if ($start && $end) {
            $query->whereBetween('created_at', [
                Carbon::parse($start)->startOfDay(),
                Carbon::parse($end)->endOfDay(), // Use Start of Day to match SQL '2026-01-10' exact behavior
            ]);
        } elseif ($start) {
            $query->where('created_at', '>=', Carbon::parse($start)->startOfDay());
        } elseif ($end) {
            $query->where('created_at', '<=', Carbon::parse($end)->endOfDay());
        }

        // 4. Status/Temperature Filters (O(1) lookups with Indexes)
        foreach (['status', 'temperature', 'source'] as $filter) {
            if ($request->filled($filter) && $request->$filter !== 'all') {
                $query->where($filter, $request->$filter);
            }
        }

        // 5. Smart Search (Optimized for PostgreSQL JSONB)
        if ($request->filled('search')) {
            $term = $request->search;

            $query->where(function ($q) use ($term) {
                // Priority 1: Numeric ID (Index Scan)
                if (is_numeric($term)) {
                    $q->where('id', $term);
                }

                // Priority 2: Case-Insensitive Text Search
                // ILIKE is native to Postgres. Ensure columns are indexed!
                $q->orWhere('source', 'ILIKE', "%{$term}%")
                    ->orWhere('notes', 'ILIKE', "%{$term}%");

                // Priority 3: JSONB Search (Optimized)
                // Instead of 'like', we use Postgres JSONB containment or casting
                // This is safer for performance than casting the whole blob to text.
                $q->orWhereRaw('payload::text ILIKE ?', ["%{$term}%"]);
            });
        }

        // 6. Resource Management (Performance & RAM)
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 5), 100);

        return $query
            // Eager load only necessary columns from relations to save RAM
            ->with(['form' => function ($q) {
                $q->select('id', 'name');
            }])
            // Order by ID Desc is faster than CreatedAt because it's the Primary Key index
            ->orderByDesc('id')
            // Use simplePaginate if you have 100k+ rows to avoid 'count(*)' overhead
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Get Lead Details
     *
     * Retrieve detailed information about a single lead, including its activities and CRM configuration.
     *
     * @authenticated
     *
     * @urlParam lead_id integer required The ID of the lead. Example: 101
     *
     * @responseFile storage/responses/leads/show.json
     *
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 404 { "message": "Lead not found." }
     */
    public function show(Lead $lead)
    {

        // 3-Layer Check via Policy
        $this->authorize('view', $lead);

        $lead->load(['activities', 'form', 'latestJob:ai_jobs.id,ai_jobs.job_uuid,ai_jobs.target_id,ai_jobs.target_type,ai_jobs.started_at,ai_jobs.completed_at,ai_jobs.status,ai_jobs.attempts']);

        // Inject CRM Config for dynamic frontend rendering
        // This attaches the config to the lead object response seamlessly
        $lead->setAttribute('crm_config', $this->getCrmConfig($lead->tenant_id));
        $lead->setAttribute('displayable_fields', $lead->getDisplayablePayload());

        return $lead;
    }

    /**
     * Update Lead
     *
     * Update specific fields of a lead. Use `suppress_webhooks` to avoid trigger loops.
     *
     * @authenticated
     *
     * @urlParam lead_id integer required The ID of the lead. Example: 101
     *
     * @response {
     *   "message": "Lead updated successfully",
     *   "id": 101
     * }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 404 { "message": "Lead not found." }
     * @response 422 { "message": "The given data was invalid.", "errors": { "status": ["The status must be a string."] } }
     */
    public function update(UpdateLeadRequest $request, Lead $lead)
    {
        // 3-Layer Check via Policy
        $this->authorize('update', $lead);

        $validated = $request->validated();

        // Fix for webhook looping:
        // External systems updating the lead can pass 'suppress_webhooks=true'
        // to avoid triggering the webhook they just reacted to.
        if ($request->boolean('suppress_webhooks')) {
            $lead->suppress_webhooks = true;
        }

        $lead->update($validated);

        return response()->json(['message' => 'Lead updated successfully', 'id' => $lead->id], 201);
    }

    /**
     * Add Lead Activity Log
     *
     * Create a new activity log entry (note) for a lead.
     *
     * @authenticated
     *
     * @urlParam lead_id integer required The ID of the lead. Example: 101
     *
     * @response {
     *   "message": "Note added successfully",
     *   "id": 101
     * }
     */
    public function addNote(StoreNoteRequest $request, Lead $lead)
    {
        // 3-Layer Check via Policy
        $this->authorize('update', $lead);

        $validated = $request->validated();

        $lead->activities()->create([
            'type' => $validated['type'] ?? 'system_added_note',
            'content' => $validated['content'],
        ]);

        return response()->json(['message' => 'Note added successfully', 'id' => $lead->id], 201);
    }

    /**
     * Create Lead
     *
     * Manually create a single lead.
     *
     * @authenticated
     *
     * @response 201 {
     *   "message": "Lead created successfully",
     *   "id": 102
     * }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 422 { "message": "The given data was invalid.", "errors": { "payload": ["The payload field is required."] } }
     */
    public function store(StoreLeadRequest $request)
    {
        $this->authorize('create', Lead::class);

        $validated = $request->validated();

        $sanitizedPayload = sanitize_payload($validated['payload']);

        // Use create() for single leads to trigger Eloquent Events/Webhooks
        $lead = new Lead([
            'tenant_id' => $request->user()->current_tenant_id,
            'form_id' => $validated['form_id'] ?? null,
            'source' => $validated['source'] ?? 'undefined',
            'payload' => $sanitizedPayload,
            'status' => 'new',
            'insert_method' => 'single',
            'temperature' => $validated['temperature'] ?? 'cold',
        ]);

        if ($request->boolean('suppress_webhooks')) {
            $lead->suppress_webhooks = true;
        }

        $lead->save();

        return response()->json(['message' => 'Lead created successfully', 'id' => $lead->id], 201);
    }

    /**
     * Shared Bulk Insert Helper
     * Handles Leads and their corresponding Activity Logs in one process.
     */
    private function performBulkInsert(array $leads, $status, $insertMethod, $temperature, $tenantId, $formId, $source, $activityType = 'system_inserted')
    {
        $batchSize = 100;
        $chunks = array_chunk($leads, $batchSize);
        $total = 0;
        $now = now();

        foreach ($chunks as $chunk) {
            $leadsToInsert = [];

            // 1. Prepare Lead Data
            foreach ($chunk as $leadPayload) {

                $sanitizedPayload = sanitize_payload($leadPayload);

                $leadsToInsert[] = [
                    'tenant_id' => $tenantId,
                    'form_id' => $formId,
                    'insert_method' => $insertMethod ?? 'bulk',
                    'source' => empty($leadPayload['source']) ? $source : $leadPayload['source'],
                    'status' => empty($leadPayload['status']) ? $status : $leadPayload['status'],
                    'temperature' => empty($leadPayload['temperature']) ? $temperature : $leadPayload['temperature'],
                    'payload' => json_encode($sanitizedPayload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::beginTransaction();
            try {
                // 2. Perform Bulk Insert for Leads
                DB::table('leads')->insert($leadsToInsert);

                // 3. Retrieve the IDs of the leads we just inserted.
                // We filter by tenant and the exact timestamp to get the correct IDs.
                $insertedLeadIds = DB::table('leads')
                    ->where('tenant_id', $tenantId)
                    ->where('created_at', $now)
                    // ->orderBy('id', 'desc')
                    ->orderByRaw('id DESC NULLS LAST') // for postgres
                    ->limit(count($chunk))
                    ->pluck('id');

                // 4. Prepare Activity Data
                // $currentTokenString = request()->user()->loggedInFromString();
                // $activityNote = ucfirst(str_replace('_', ' ', $activityType)) . " a lead, using " . strtoupper($insertMethod) . " upload.";
                $activityNote = 'Inserted a lead, using '.strtoupper($insertMethod).' upload.';
                $activitiesToInsert = [];
                foreach ($insertedLeadIds as $leadId) {
                    $activitiesToInsert[] = [
                        'lead_id' => $leadId,
                        'type' => $activityType,
                        'content' => $activityNote,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // 5. Bulk Insert Activities
                if (! empty($activitiesToInsert)) {
                    DB::table('lead_activities')->insert($activitiesToInsert);
                }

                DB::commit();
                $total += count($leadsToInsert);
            } catch (\Exception $e) {
                DB::rollBack();
                // Log the error but continue or rethrow based on your agency's policy
                Log::error('Batch Insert Failed: '.$e->getMessage());
                throw $e;
            }
        }

        return $total;
    }

    /**
     * Create Leads (In Batch)
     *
     * Process multiple leads in a single request. Highly efficient for bulk operations.
     *
     * @authenticated
     *
     * @response {
     *   "message": "Successfully processed 50 leads."
     * }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 422 { "message": "The given data was invalid.", "errors": { "leads": ["The leads field is required."] } }
     */
    public function batchStore(BatchStoreLeadRequest $request)
    {
        $this->authorize('create', Lead::class);

        $validated = $request->validated();

        $leadsData = $validated['leads'];
        $tenantId = $request->user()->current_tenant_id;
        $formId = $request->form_id ?? null;
        $source = $request->input('source', 'undefined');
        $temperature = $request->input('temperature', 'cold');
        $status = $request->input('status', 'new');
        $activityType = $request->input('from', 'system_inserted');

        // We use the helper method to keep things efficient
        $count = $this->performBulkInsert($leadsData, $status, 'bulk', $temperature, $tenantId, $formId, $source, $activityType);

        return response()->json(['message' => "Successfully processed {$count} leads."]);
    }

    /**
     * Import Leads
     *
     * Upload a CSV file to import leads in bulk.
     *
     * @authenticated
     *
     * @response {
     *   "message": "Imported 100 leads."
     * }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 422 { "message": "The given data was invalid.", "errors": { "file": ["The file field is required."] } }
     */
    public function import(ImportLeadRequest $request)
    {
        $this->authorize('create', Lead::class);

        $validated = $request->validated();

        $file = $request->file('file');
        $handle = fopen($file->getPathname(), 'r');
        $header = fgetcsv($handle); // Consume and skip header row

        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'Empty file'], 400);
        }

        $allLeads = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row)) || count($row) !== count($header)) {
                continue;
            }
            $allLeads[] = array_combine($header, $row);
        }
        fclose($handle);

        $count = $this->performBulkInsert(
            $allLeads,
            $request->status ?? 'new',
            'csv',
            $request->temperature ?? 'cold',
            $request->user()->current_tenant_id,
            $request->form_id ?? null,
            $request->source ?? 'undefined',
            $request->input('from', 'system_inserted')

        );

        return response()->json(['message' => "Imported {$count} leads."]);
    }

    /**
     * Export Leads
     *
     * Download a CSV export of leads. You can optionally filter by IDs.
     *
     * @authenticated
     *
     * @response 200 {
     *  "description": "Binary CSV data stream"
     * }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     */
    public function export(ExportLeadRequest $request)
    {
        $this->authorize('viewAny', Lead::class);

        $validated = $request->validated();

        $tenantId = $request->user()->current_tenant_id;
        $selectedIds = $validated['ids'] ?? [];

        // 1. DISCOVERY PHASE: Find all unique payload keys
        // We use a separate query to get only the payloads to save RAM.
        // LIMIT discovery to the latest 1000 leads to avoid catastrophic full table scans.
        $query = Lead::where('tenant_id', $tenantId);
        if (! empty($selectedIds)) {
            $query->whereIn('id', $selectedIds);
        } else {
            $query->latest('id')->limit(1000);
        }

        $allKeys = [];
        // We use cursor to avoid loading thousands of objects into RAM
        foreach ($query->select('payload')->cursor() as $lead) {
            $keys = array_keys($lead->payload ?? []);
            foreach ($keys as $key) {
                $allKeys[$key] = true; // Use keys as array keys to auto-handle duplicates
            }
        }
        $dynamicHeaders = array_keys($allKeys);

        // 2. STREAMING PHASE
        $fileName = 'leads_export_'.now()->format('Y-m-d_H-i').'.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($tenantId, $selectedIds, $dynamicHeaders) {
            $file = fopen('php://output', 'w');

            // Write the full header row
            $mainHeaders = array_merge(['id', 'status', 'temperature', 'source'], $dynamicHeaders, ['created_at']);
            fputcsv($file, $mainHeaders);

            // Fetch data again for the actual export
            $exportQuery = Lead::where('tenant_id', $tenantId);
            if (! empty($selectedIds)) {
                $exportQuery->whereIn('id', $selectedIds);
            }

            foreach ($exportQuery->cursor() as $lead) {
                $row = [
                    $lead->id,
                    $lead->status,
                    $lead->temperature,
                    $lead->source,
                ];

                // Map dynamic payload data to the correct column
                foreach ($dynamicHeaders as $header) {
                    // $row[] = $lead->payload[$header] ?? ''; // Leave empty if key doesn't exist for this lead
                    $value = $lead->payload[$header] ?? '';

                    // FIX: Ensure nested arrays/objects are converted to strings
                    $row[] = is_array($value) || is_object($value)
                        ? json_encode($value)
                        : (string) $value;
                }

                $row[] = $lead->created_at->toDateTimeString();
                // In 2026 (PHP 8.4+), explicitly provide the escape parameter to avoid deprecation
                // fputcsv($file, $row);
                fputcsv($file, $row, separator: ',', enclosure: '"', escape: '');
            }

            fclose($file);
        }, 200, $headers);
    }
}
