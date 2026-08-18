<?php

namespace App\Console\Commands;

use App\Models\ExternalApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class GenerateSidecarKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-sidecar-key {--for=mcp_sidecar : Purpose description of the key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a platform API Key/Secret pair for AI sidecar communication and cache it in Redis';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $for = $this->option('for');
        $appId = 'app_' . bin2hex(random_bytes(8));
        $plaintextSecret = 'sk_live_' . bin2hex(random_bytes(16));

        // 1. Create or update in database
        $apiKey = ExternalApiKey::create([
            'app_id' => $appId,
            'secret' => encrypt($plaintextSecret),
            'for' => $for,
            'is_active' => true,
        ]);

        // 2. Cache plaintext secret in Redis for high-speed Sidecar auth lookup
        Redis::set("apikey:{$appId}", $plaintextSecret);

        $this->info('----------------------------------------------------');
        $this->info(' Sidecar API Credentials Generated Successfully');
        $this->info('----------------------------------------------------');
        $this->line("<comment>MCP_SIDECARD_CLIENT_APP_ID=</comment><info>{$appId}</info>");
        $this->line("<comment>MCP_SIDECARD_CLIENT_SECRET=</comment><info>{$plaintextSecret}</info>");
        $this->info('----------------------------------------------------');
        $this->info('Copy these values into your infra/.env file and restart the containers.');

        return Command::SUCCESS;
    }
}
