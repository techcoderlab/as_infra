<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldMappingTest extends TestCase
{
    use RefreshDatabase; // Use with caution on local dev, maybe just create/delete

    public function test_third_party_form_submission_filters_fields_based_on_mapping()
    {
        // 1. Create a Form with fields_needed mapping
        $mapping = [
            'wanted_field' => 'Wanted Label',
            'another_wanted' => 'Another Label',
        ];

        $tenant = new \App\Models\Tenant;
        $tenant->name = 'Test Tenant';
        $tenant->save();

        $form = Form::create([
            'name' => 'Test Mapping Form',
            'form_source' => 'third_party',
            'ref_form_id' => 'test_form_123',
            'is_active' => true,
            'fields_needed' => $mapping,
            'tenant_id' => $tenant->id,
            'schema' => [],
        ]);

        // 2. Prepare payload with extra fields
        $payload = [
            'data' => [
                'formId' => 'test_form_123',
                'formSource' => 'third_party',
                'ms_since_load' => 3000,
                'fields' => [
                    'wanted_field' => 'Value 1',
                    'another_wanted' => 'Value 2',
                    'unwanted_field' => 'Should be removed',
                    'random_data' => 'Remove me',
                ],
            ],
        ];

        // 3. Submit to the endpoint
        $response = $this->postJson('/api/public/external/form/submit', $payload);

        $response->assertStatus(201);

        // 4. Verify the created Lead has filtered payload
        $lead = Lead::where('form_id', $form->id)->latest()->first();

        $this->assertNotNull($lead);
        $this->assertArrayHasKey('wanted_field', $lead->payload);
        $this->assertArrayHasKey('another_wanted', $lead->payload);
        $this->assertArrayNotHasKey('unwanted_field', $lead->payload);
        $this->assertArrayNotHasKey('random_data', $lead->payload);

        // Cleanup
        $lead->delete();
        $form->delete();
    }
}
