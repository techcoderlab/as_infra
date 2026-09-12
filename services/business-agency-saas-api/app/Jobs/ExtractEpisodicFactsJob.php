<?php
// ─────────────────────────────────────────────────────
// Module   : Episodic Facts Job
// Layer    : Application / Infrastructure
// Pillar   : P1 Architecture, P3 Concurrency, P6 Resilience
// ─────────────────────────────────────────────────────

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtractEpisodicFactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 30];
    }

    protected int $tenantId;
    protected int $leadId;
    protected array $recentTurns;
    protected ?string $sourceTurnId;

    /**
     * Create a new job instance.
     *
     * @param int $tenantId
     * @param int $leadId
     * @param array $recentTurns The recent conversation turns in format: [['role' => 'user', 'content' => '...'], ...]
     * @param string|null $sourceTurnId
     */
    public function __construct(int $tenantId, int $leadId, array $recentTurns, ?string $sourceTurnId = null)
    {
        $this->tenantId = $tenantId;
        $this->leadId = $leadId;
        $this->recentTurns = $recentTurns;
        $this->sourceTurnId = $sourceTurnId;
    }

    /**
     * Execute the job.
     * P6 Resilience: Wraps external API call with retry logic and logs failures.
     */
    public function handle(): void
    {
        // Convert to expected sidecar format (ensure consistent role naming)
        $formattedTurns = [];
        foreach ($this->recentTurns as $turn) {
            $formattedTurns[] = [
                'role' => $turn['role'] ?? 'unknown',
                'content' => $turn['content'] ?? '',
            ];
        }

        $payload = [
            'tenant_id' => $this->tenantId,
            'lead_id' => $this->leadId,
            'recent_turns' => $formattedTurns,
            'source_turn_id' => $this->sourceTurnId,
        ];

        $url = config('services.mcp_sidecar.url') . '/v1/memory/extract-facts';
        
        Log::info('[EpisodicMemoryJob] Sending facts extraction request', [
            'tenant_id' => $this->tenantId,
            'lead_id' => $this->leadId,
            'turns_count' => count($formattedTurns),
            'url' => $url,
        ]);

        $response = Http::timeout(60)
            ->withHeaders([
                'X-Service-ID' => config('services.mcp_sidecar.calling_api_name'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('[EpisodicMemoryJob] Sidecar API request failed', [
                'tenant_id' => $this->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            // Throw exception to trigger job retry
            $response->throw();
        }

        Log::info('[EpisodicMemoryJob] Extraction completed successfully', [
            'tenant_id' => $this->tenantId,
            'result' => $response->json(),
        ]);
    }
}
