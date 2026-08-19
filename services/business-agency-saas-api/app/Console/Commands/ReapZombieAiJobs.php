<?php
# ─────────────────────────────────────────────────────
# Module   : Console Command
# ─────────────────────────────────────────────────────

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AiJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ReapZombieAiJobs extends Command
{
    protected $signature = 'ai:reap-zombies';
    protected $description = 'Mark AI jobs stuck in processing for more than a configured threshold (default 20 mins) as failed';

    public function handle()
    {
        // Flexible configuration via config, defaulting to 20 minutes
        $thresholdMinutes = config('services.mcp_sidecar.reaper_timeout_minutes', 20);
        $threshold = Carbon::now()->subMinutes($thresholdMinutes);

        $zombies = AiJob::whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'failed',
                'error_message' => "Job timed out: Stuck in processing for more than {$thresholdMinutes} minutes (Reaper).",
            ]);

        if ($zombies > 0) {
            Log::warning("[ReapZombieAiJobs]: Reaped {$zombies} zombie AI jobs.");
            $this->info("Reaped {$zombies} zombie jobs.");
        } else {
            $this->info("No zombie jobs found.");
        }
    }
}
