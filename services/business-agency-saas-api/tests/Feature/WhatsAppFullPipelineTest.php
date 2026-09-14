<?php

namespace Tests\Feature;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WhatsAppFullPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_webhook_pipeline()
    {
        // 1. Force the queue to run synchronously so the job executes immediately
        Config::set('queue.default', 'sync');

        // 2. Setup database integration
        $appSecret = 'abc@1234';
        $integration = Integration::forceCreate([
            'tenant_id' => 5,
            'service' => 'whatsapp',
            'is_active' => true,
            'value' => ['app_secret' => $appSecret]
        ]);

        // 3. Create a realistic fake payload
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '12345',
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    ['from' => '1234567890', 'text' => ['body' => 'Hello!']]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // 4. Generate the exact signature WhatsApp would send
        $bodyContent = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $bodyContent, $appSecret);

        // 5. Fire the POST request
        $response = $this->call('POST', 'api/whatsapp/5/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature
        ], $bodyContent);

        $response->assertStatus(200);

        // 6. Assert the job actually did its work!
        // Replace this with whatever your job is supposed to do (e.g., check database)
        // $this->assertDatabaseHas('messages', ['body' => 'Hello!']);
    }
}