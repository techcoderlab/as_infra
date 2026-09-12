<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessKnowledgeSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    public function __construct(
        protected KnowledgeSource $knowledgeSource
    ) {
    }

    public function handle(): void
    {
        $this->knowledgeSource->update(['status' => 'processing']);

        try {
            $sidecarUrl = config('services.mcp_sidecar.url');
            if (!$sidecarUrl) {
                throw new \Exception('MCP Sidecar URL is not configured.');
            }

            // Default to title
            $sourceRef = $this->knowledgeSource->title;

            // Bypass Nginx symlink issues by using the internal PHP download route
            if (!empty($this->knowledgeSource->source_url)) {
                $sourceRef = 'http://gateway:80/api/internal/knowledge-sources/' . $this->knowledgeSource->id . '/download';
            }

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.mcp_sidecar.token'),
                    'Accept' => 'application/json',
                ])
                ->post("{$sidecarUrl}/v1/memory/ingest", [
                    'tenant_id' => $this->knowledgeSource->tenant_id,
                    'source_id' => $this->knowledgeSource->id,
                    'source_type' => $this->knowledgeSource->source_type,
                    'source_ref' => $sourceRef, // Internal URL goes here
                    'content' => $this->knowledgeSource->content,
                ]);

            if ($response->successful()) {
                $this->knowledgeSource->update([
                    'status' => 'indexed',
                ]);
                Log::info("[ProcessKnowledgeSourceJob] Successfully indexed source {$this->knowledgeSource->id}");
            } else {
                $this->knowledgeSource->update([
                    'status' => 'failed',
                ]);
                Log::error("[ProcessKnowledgeSourceJob] Sidecar error for source {$this->knowledgeSource->id}: " . $response->body());
                $this->fail(new \Exception('Sidecar returned error: ' . $response->body()));
            }
        } catch (\Exception $e) {
            $this->knowledgeSource->update([
                'status' => 'failed',
            ]);
            Log::error("[ProcessKnowledgeSourceJob] Exception for source {$this->knowledgeSource->id}: " . $e->getMessage());
            throw $e;
        }
    }
}
