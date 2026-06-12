<?php

namespace Tests\Unit\Services;

use App\Models\Integration;
use App\Services\WhatsAppServiceNative;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    protected $tenantId = 1;

    protected $service;

    protected $integration;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Integration model
        $this->integration = Mockery::mock(Integration::class.'[getAttribute]');
        $this->integration->shouldReceive('getAttribute')->with('value')->andReturn([
            'phone_id' => '123456789',
            'api_key' => 'fake-token',
        ]);

        $this->service = new WhatsAppServiceNative($this->tenantId, $this->integration);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_sends_text_message_successfully()
    {
        Http::fake([
            '*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [], 'messages' => [['id' => 'waid']]], 200),
        ]);

        $result = $this->service->sendMessage('1234567890', 'Hello World');

        $this->assertIsArray($result);
        Http::assertSent(function ($request) {
            return $request['type'] === 'text' && $request['text']['body'] === 'Hello World';
        });
    }

    public function test_it_sends_template_message_successfully()
    {
        Http::fake([
            '*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [], 'messages' => [['id' => 'waid']]], 200),
        ]);

        $result = $this->service->sendMessage('1234567890', 'welcome_template', ['type' => 'template', 'language' => 'en_US']);

        $this->assertIsArray($result);
        Http::assertSent(function ($request) {
            return $request['type'] === 'template' && $request['template']['name'] === 'welcome_template';
        });
    }

    public function test_it_handles_rate_limiting()
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->with('whatsapp_limit_123456789', 20)
            ->andReturn(true);

        RateLimiter::shouldReceive('availableIn')
            ->once()
            ->andReturn(5);

        $result = $this->service->sendMessage('1234567890', 'Hello');

        $this->assertFalse($result);
    }

    public function test_it_handles_meta_api_errors()
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Invalid Parameter']], 400),
        ]);

        $result = $this->service->sendMessage('1234567890', 'Hello');

        $this->assertFalse($result);
    }
}
