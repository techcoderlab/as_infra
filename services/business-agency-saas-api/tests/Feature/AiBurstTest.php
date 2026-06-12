<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadChatSession;
use App\Models\Tenant;
use App\Services\Ai\DebounceService;
use App\Services\TriggerAiAgentJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AiBurstTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $lead;

    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = new Tenant;
        $this->tenant->name = 'Test Tenant';
        $this->tenant->save();

        $this->lead = new Lead;
        $this->lead->tenant_id = $this->tenant->id;
        $this->lead->source = 'whatsapp';
        $this->lead->status = 'new';
        $this->lead->payload = ['wa_id' => '123456'];
        $this->lead->save();

        $this->session = new LeadChatSession;
        $this->session->tenant_id = $this->tenant->id;
        $this->session->lead_id = $this->lead->id;
        $this->session->platform = 'whatsapp';
        $this->session->platform_user_id = '123456';
        $this->session->status = 'active';
        $this->session->last_interaction_at = now();
        $this->session->save();
    }

    public function test_first_message_triggers_immediately()
    {
        $mockTrigger = Mockery::mock(TriggerAiAgentJobService::class);
        $this->app->instance(TriggerAiAgentJobService::class, $mockTrigger);

        $mockTrigger->shouldReceive('trigger')
            ->once();

        $debounceService = app(DebounceService::class);
        $debounceService->trigger($this->lead, $this->session);

        $sessionKey = DebounceService::getSessionKey($this->tenant->id, '123456');
        $this->assertTrue(Cache::has("{$sessionKey}:processing"));
    }

    public function test_subsequent_messages_in_burst_set_dirty_flag()
    {
        $mockTrigger = Mockery::mock(TriggerAiAgentJobService::class);
        $this->app->instance(TriggerAiAgentJobService::class, $mockTrigger);

        // First message triggers
        $mockTrigger->shouldReceive('trigger')->once();

        $debounceService = app(DebounceService::class);
        $debounceService->trigger($this->lead, $this->session);

        // Second message while processing
        $debounceService->trigger($this->lead, $this->session);

        $sessionKey = DebounceService::getSessionKey($this->tenant->id, '123456');
        $this->assertTrue(Cache::has("{$sessionKey}:dirty"));
    }

    public function test_finalize_re_triggers_if_dirty()
    {
        $mockTrigger = Mockery::mock(TriggerAiAgentJobService::class);
        $this->app->instance(TriggerAiAgentJobService::class, $mockTrigger);

        // Expect two triggers: one initial, one after finalize due to dirty flag
        $mockTrigger->shouldReceive('trigger')->twice();

        $debounceService = app(DebounceService::class);

        // 1. Initial trigger
        $debounceService->trigger($this->lead, $this->session);

        // 2. Burst message marks as dirty
        $debounceService->trigger($this->lead, $this->session);

        $sessionKey = DebounceService::getSessionKey($this->tenant->id, '123456');

        // 3. Finalize (AI Turn finished)
        $debounceService->finalize($sessionKey);

        $this->assertFalse(Cache::has("{$sessionKey}:dirty"));
        // Finalize also sets processing back to true because it re-triggers
        $this->assertTrue(Cache::has("{$sessionKey}:processing"));
    }
}
