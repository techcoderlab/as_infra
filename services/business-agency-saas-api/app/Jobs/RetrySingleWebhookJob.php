<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetrySingleWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [5, 30, 120];

    public function __construct(
        public array $data,
        public Webhook $webhook,
        public string $event
    ) {}

    public function handle(): void
    {
        if (!$this->webhook->is_active) {
            return;
        }

        $headers = [
            'User-Agent' => 'AgencySaaS-Webhook/1.0',
            'Content-Type' => 'application/json',
        ];

        if ($this->webhook->secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($this->data), $this->webhook->secret);
        }

        $method = strtolower($this->webhook->method ?? 'post');

        try {
            Http::withHeaders($headers)
                ->timeout(10)
                ->send($method, $this->webhook->url, [
                    'json' => $this->data
                ])
                ->throw();
        } catch (\Exception $e) {
            Log::error("Webhook {$this->webhook->id} failed to deliver event {$this->event}: " . $e->getMessage());
            throw $e;
        }
    }
}
