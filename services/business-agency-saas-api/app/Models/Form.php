<?php

namespace App\Models;

use App\Jobs\DispatchWebhookBatchJob;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model; // Use this instead of HasUuids
use Illuminate\Support\Facades\Cache;

class Form extends Model
{
    use BelongsToTenant;
    use HasFactory;

    // use HasUuids;
    use HasVersion4Uuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'schema',
        'is_active',
        'form_source',
        'form_public_url',
        'ref_form_id',
        'fields_needed',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_active' => 'boolean',
        'fields_needed' => 'array',
    ];

    public function clearCache(): void
    {
        // Collect all possible identifiers
        $ids = array_filter([$this->getKey(), $this->ref_form_id]);

        foreach ($ids as $id) {
            Cache::forget("form_tracker_{$id}");
            Cache::forget("form_submit_{$id}");
        }
    }

    public function webhooks()
    {
        return $this->hasMany(Webhook::class, 'form_id');
    }

    public function triggerWebhooks(string $event, array $payloadData = []): void
    {
        if (empty($payloadData)) {
            throw new \Exception("Webhook payload data is required.");
        }

        if (empty($event)) {
            throw new \Exception("Webhook event is required.");
        }

        if (empty($this->getKey())) {
            throw new \Exception("Webhook form ID is required.");
        }
        // 5. Webhook Logic (Read Cache + Dispatch)
        // We can do this outside the transaction to keep the transaction block short.
        $webhooks = Cache::remember(
            "form:webhooks:{$this->getKey()}",
            now()->addMinutes(5),
            fn () => $this->webhooks()
                ->where('is_active', true)
                ->whereJsonContains('events', $event)
                ->get()
        );

        if ($webhooks->isNotEmpty()) {
            DispatchWebhookBatchJob::dispatch(
                data: $payloadData,
                webhooks: $webhooks,
                event: $event
            );
        }
    }
    // public function triggerWebhooks(string $event, Lead $model)
    // {
    //     // 5. Webhook Logic (Read Cache + Dispatch)
    //     // We can do this outside the transaction to keep the transaction block short.
    //     $webhooks = Cache::remember(
    //         "form:webhooks:{$this->getKey()}",
    //         now()->addMinutes(5),
    //         fn () => $this->webhooks()
    //             ->where('is_active', true)
    //             ->whereJsonContains('events', $event)
    //             ->get()
    //     );

    //     if ($webhooks->isNotEmpty()) {
    //         DispatchWebhookBatchJob::dispatch(
    //             data: [
    //                 'id' => $model->getKey(),
    //                 'payload' => $model->payload ?? [],
    //                 'source' => 'form',
    //                 'created_at' => $model->created_at,
    //             ],
    //             webhooks: $webhooks,
    //             event: $event
    //         );
    //     }
    // }
}
