<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessKnowledgeSourceJob;
use App\Models\KnowledgeSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KnowledgeHubController extends Controller
{
    public function index(Request $request)
    {
        $sources = KnowledgeSource::where('tenant_id', $request->user()->current_tenant_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'sources' => $sources,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'source_type' => 'required|in:document,url,manual_note',
            'source_url' => 'nullable|string|url',
            'content' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,doc,docx,txt,md|max:10240', // 10MB max
            'metadata' => 'nullable|array',
        ]);

        $sourceUrl = $validated['source_url'] ?? null;

        // Handle file upload
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            // Store locally or in S3 depending on your storage config
            $path = $file->store('knowledge-sources', 'public');
            // Generate a full URL so the sidecar can download it
            $sourceUrl = url(\Illuminate\Support\Facades\Storage::url($path));
        }

        $source = KnowledgeSource::create([
            'tenant_id' => $request->user()->tenant_id,
            'title' => $validated['title'],
            'source_type' => $validated['source_type'],
            'source_url' => $sourceUrl,
            'content' => $validated['content'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'is_active' => true,
            'status' => 'pending',
        ]);

        // Dispatch ingestion job to sidecar
        ProcessKnowledgeSourceJob::dispatch($source);

        return response()->json([
            'message' => 'Knowledge source created and queued for processing.',
            'source' => $source,
        ], 201);
    }

    public function toggleStatus(Request $request, $id)
    {
        $source = KnowledgeSource::where('tenant_id', $request->user()->current_tenant_id)->findOrFail($id);

        $source->update([
            'is_active' => !$source->is_active,
        ]);

        return response()->json([
            'message' => 'Status toggled successfully.',
            'source' => $source,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $source = KnowledgeSource::where('tenant_id', $request->user()->current_tenant_id)->findOrFail($id);

        // We will dispatch a deletion request to the sidecar to remove vectors,
        // or rely on cascading deletes if sidecar has a webhook, but usually
        // we can just call the sidecar directly or queue it. 
        // For now, deleting it here will cascade delete `agent_knowledge_source`.
        // The Sidecar should ideally have a `DELETE /api/v1/memory/sources/{id}` endpoint.

        try {
            $sidecarUrl = config('services.mcp_sidecar.url');
            if ($sidecarUrl) {
                \Illuminate\Support\Facades\Http::timeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('services.mcp_sidecar.token'),
                        'Accept' => 'application/json',
                    ])
                    ->delete("{$sidecarUrl}/v1/memory/sources/{$source->id}");
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[KnowledgeHubController] Failed to notify sidecar of deletion: " . $e->getMessage());
        }

        $source->delete();

        return response()->json([
            'message' => 'Knowledge source deleted successfully.',
        ]);
    }

    /**
     * Serves the file directly via PHP to bypass Docker Nginx symlink limitations.
     */
    /**
     * Serves local storage files directly to Python Sidecar using Secret Token Authentication.
     */
    public function internalDownload(Request $request, $id)
    {
        // 2. Fetch Source Record
        $source = KnowledgeSource::findOrFail($id);

        if (!$source->source_url) {
            return response()->json(['error' => 'No URL set for source.'], 404);
        }

        // FIX: Add safeguard to ensure we only attempt to serve uploaded documents
        if ($source->source_type !== 'document') {
            return response()->json(['error' => 'Only documents can be downloaded internally.'], 400);
        }

        // 3. Robust path extraction: Extract relative path after '/storage/'
        $parsedPath = parse_url($source->source_url, PHP_URL_PATH);

        // Strip leading '/storage/' or 'storage/' and any trailing/leading slashes
        $relativePath = preg_replace('#^/?storage/#', '', $parsedPath);
        $relativePath = trim($relativePath, '/');

        // FIX: Ensure $relativePath isn't empty (which would make exists() evaluate the root directory)
        if (empty($relativePath) || !Storage::disk('public')->exists($relativePath)) {
            return response()->json([
                'error' => 'File not found on public storage disk.',
                'debug_path' => $relativePath
            ], 404);
        }

        return Storage::disk('public')->response($relativePath);
    }
}
