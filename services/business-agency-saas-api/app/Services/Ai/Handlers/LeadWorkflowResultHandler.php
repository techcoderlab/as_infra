<?php

namespace App\Services\Ai\Handlers;

use App\Jobs\DispatchWebhookBatchJob;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Services\Ai\Contracts\WorkflowResultHandler;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LeadWorkflowResultHandler implements WorkflowResultHandler
{
    use \App\Services\Ai\Concerns\ResolvesWorkflowTarget;

    /**
     * Handle the result of a Lead Workflow AI job.
     */
    public function handle(Tenant $tenant, WorkflowPayload $payload, array $result): void
    {
        // 1. Resolve Target
        $target = $this->resolveTarget($payload);

        if (!$target) {
            return;
        }

        // Check if Lead is valid
        if (!($target instanceof Lead)) {
            Log::error('[LeadWorkflowResultHandler]: Target is not a Lead.', ['target_type' => get_class($target)]);

            return;
        }

        // 2. Parse the AI Response
        // The sidecar returns 'response' which should be the JSON string defined in System Prompt
        $rawJson = $result['response'] ?? '{}';
        $parsedData = clean_and_decode_json($rawJson);

        if (isset($parsedData['error']) && $parsedData['error'] === true) {
            Log::error('[LeadWorkflowResultHandler]: Error in lead workflow.', ['raw_response' => $rawJson]);

            return;
        }

        $thoughtStream = $result['thoughtStream'] ?? [];

        try {

            // Update the score of the lead if available in the response
            if (isset($parsedData['score'])) {
                if ($target->score !== (int) $parsedData['score']) {
                    $target->updateQuietly(['score' => (int) $parsedData['score']]);
                }
            }

            // Log the Activity / Audit Trail for Lead Timeline
            $this->logActivity($target, $parsedData, $thoughtStream);
        } catch (\Exception $e) {
            Log::error('[LeadWorkflowResultHandler]: ' . $e->getMessage());
        }

    }

    /**
     * Create a human-readable audit log of the Agent's actions.
     *
     * @throws \Exception
     */
    protected function logActivity(Model $target, array $parsedData, array $thoughtStream): void
    {
        // Add detailed parsedData
        // Extract only the keys you need
        $neededData = collect($parsedData ?? [])
            ->only(['summary', 'reasoning', 'recommended_action'])
            ->map(fn($v) => is_numeric($v) ? (string) $v : trim($v))
            ->filter(); // Automatically removes null, false, and empty strings, but keeps "0"

        if ($neededData->isEmpty()) {
            return;
        }

        // Option A: Keeps them separated by newlines
        // $details = $neededData->implode(PHP_EOL);

        // Option B (Recommended): Format with labels so the text makes sense
        $details = $neededData->map(fn($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ": $v")
            ->implode(PHP_EOL);

        $activity = [
            'lead_id' => $target->id,
            'type' => 'ai_updated',
            'content' => $details,
            'metadata' => json_encode([
                'responseData' => $parsedData ?? [],
                'thoughtStream' => $thoughtStream ?? [],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        LeadActivity::insert([$activity]);


        $form = $target->form;
        if ($form) {
            // Trigger the discord webhook if the form is a public form

            $str = '';
            foreach ($target->payload ?? [] as $k => $v) {
                $lbl = ucwords(str_replace(['_', '-'], ' ', $k));
                $val = is_string($v) || is_numeric($v) ? $v : json_encode($v);
                $str .= "**$lbl:** $val\n";
            }
            $formattedPayload = substr(trim($str) ?: '*No data*', 0, 1024);


            $event = 'form.submission';
            $form->triggerWebhooks($event, [
                'embeds' => [
                    [
                        'title' => 'New Solar Lead Captured - SUNSTATESOLAR',
                        'color' => 3066993, // A nice green/teal hex code in decimal
                        'timestamp' => $target->created_at->toIso8601String(),
                        'fields' => [
                            [
                                'name' => '📋 Form Data',
                                'value' => $formattedPayload,
                                'inline' => false
                            ],
                            [
                                'name' => '🧠 AI Analysis',
                                'value' => $neededData->except('reasoning')
                                    ->map(fn($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ": $v")
                                    ->implode(PHP_EOL),
                                'inline' => false
                            ]
                        ],
                        'footer' => [
                            'text' => 'From Blue Rio Systems - AI Lead Bot'
                        ]
                    ]
                ]
            ]);


            // $webhooks = Cache::remember(
            //     "form:webhooks:{$form->getKey()}",
            //     now()->addMinutes(5),
            //     fn () => $form->webhooks()
            //         ->where('is_active', true)
            //         ->whereJsonContains('events', 'form.submission')
            //         ->get()
            // );
            // if ($webhooks->isNotEmpty()) {

            //     $str = '';
            //     foreach ($target->payload ?? [] as $k => $v) {
            //         $lbl = ucwords(str_replace(['_', '-'], ' ', $k));
            //         $val = is_string($v) || is_numeric($v) ? $v : json_encode($v);
            //         $str .= "**$lbl:** $val\n";
            //     }
            //     $formattedPayload = substr(trim($str) ?: '*No data*', 0, 1024);

            //     DispatchWebhookBatchJob::dispatch(
            //         data: [
            //             'embeds' => [
            //                 [
            //                     'title' => 'New Solar Lead Captured - SUNSTATESOLAR',
            //                     'color' => 3066993, // A nice green/teal hex code in decimal
            //                     'timestamp' => $target->created_at->toIso8601String(),
            //                     'fields' => [
            //                         [
            //                             'name' => '📋 Form Data',
            //                             'value' => $formattedPayload,
            //                             'inline' => false
            //                         ],
            //                         [
            //                             'name' => '🧠 AI Analysis',
            //                             'value' => $details,
            //                             'inline' => false
            //                         ]
            //                     ],
            //                     'footer' => [
            //                         'text' => 'From Blue Rio Systems - AI Lead Bot'
            //                     ]
            //                 ]
            //             ]
            //         ],
            //         webhooks: $webhooks,
            //         event: 'form.submission'
            //     );
            // }
        }
    }
}
