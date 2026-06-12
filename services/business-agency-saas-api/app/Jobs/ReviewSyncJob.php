<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReviewSyncJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $accounts = \App\Models\GoogleAccount::where('is_active', true)->get();
        $service = app(\App\Services\GoogleBusinessProfileService::class);
        $aiGateway = app(\App\Services\Ai\AiGateway::class);

        foreach ($accounts as $account) {
            if (! $account->location_name) {
                continue;
            }

            $reviews = $service->fetchReviews($account, $account->location_name);

            foreach ($reviews as $review) {
                $reviewId = $review['name']; // Google's unique review name/id

                // Check if already synced
                $existing = \App\Models\Lead::where('tenant_id', $account->tenant_id)
                    ->where('source', 'google_review')
                    ->whereJsonContains('payload->google_review_id', $reviewId)
                    ->first();

                if ($existing) {
                    continue;
                }

                // Create new Lead for this review
                $lead = \App\Models\Lead::create([
                    'tenant_id' => $account->tenant_id,
                    'source' => 'google_review',
                    'status' => 'pending',
                    'temperature' => 'neutral', // Default, AI will update this
                    'payload' => [
                        'google_review_id' => $reviewId,
                        'reviewer_name' => $review['reviewer']['displayName'] ?? 'Anonymous',
                        'star_rating' => $review['starRating'] ?? 0,
                        'comment' => $review['comment'] ?? '',
                        'create_time' => $review['createTime'] ?? now()->toIso8601String(),
                    ],
                ]);

                // Trigger AI Agent to draft a response
                $aiGateway->executeAgent('review-autopilot', $lead);
            }
        }
    }
}
