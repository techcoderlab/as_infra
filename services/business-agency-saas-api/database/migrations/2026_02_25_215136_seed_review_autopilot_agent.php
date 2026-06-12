<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tenants = \Illuminate\Support\Facades\DB::table('tenants')->get();

        foreach ($tenants as $tenant) {
            \Illuminate\Support\Facades\DB::table('ai_agents')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'review-autopilot',
                ],
                [
                    'name' => 'Review & Reputation Autopilot',
                    'model' => 'gemini-2.0-flash',
                    'brain' => 'gemini',
                    'system_prompt' => "You are an elite Reputation Manager for a local business.

[MISSION]
Analyze the customer's Google Review and draft a professional, empathetic, and on-brand response.

[OUTPUT REQUIREMENTS]
You MUST return a JSON object with exactly two keys:
1. \"reply\": Your drafted response. Keep it concise, friendly, and helpful. Mention specifics from the review if applicable.
2. \"sentiment\": A single word: \"positive\", \"neutral\", or \"negative\".

[GUIDELINES]
- For 5-star reviews: Be enthusiastic and grateful.
- For 3-4 star reviews: Be helpful and address any minor concerns.
- For 1-2 star reviews: Be professional, apologetic, and invite them to discuss further offline. NEVER be defensive.
- Always sound like a human, not a robot.",
                    'is_active' => true,
                    'handler_class' => 'App\Services\Ai\Handlers\ReviewResponseResultHandler',
                    'tool_configs' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('ai_agents')->where('slug', 'review-autopilot')->delete();
    }
};
