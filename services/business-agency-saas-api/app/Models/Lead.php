<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToTenant;
    use HasFactory;

    // Temporary flag to prevent observer from firing webhooks during sync updates
    public bool $suppress_webhooks = false;

    protected $fillable = [
        'tenant_id',
        'form_id',
        'source',
        'insert_method',
        'temperature',
        'status',
        'score',
        'payload',
        'meta_data',
        'notes',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta_data' => 'array',
    ];

    /**
     * PRIVACY FIX: Explicitly define what data is safe for the AI Context.
     * Prevents PII leakage (GDPR) and token wastage.
     */
    public function toAiContext(): array
    {
        $payload = (array) ($this->payload ?? []);
        
        // PII Masking: Redact sensitive fields
        $piiKeys = ['email', 'phone', 'ssn', 'password', 'credit_card', 'cc_number', 'dob', 'address', 'first_name', 'last_name', 'name'];
        foreach ($piiKeys as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return [
            'id' => $this->getKey(),
            'status' => $this->status,
            'temperature' => $this->temperature,
            'source' => $this->source,
            'score' => $this->score,
            'won' => $this->won,
            // Only expose specific safe fields from payload/metadata
            'payload' => !empty($payload) ? $payload : null,
            // 'recent_activity' => $this->activities()->limit(5)->get()->map(fn($a) => $a->content)->toArray(),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }

    public function getDisplayablePayload()
    {
        $form = $this->form;

        if (empty($form->fields_needed)) {
            return $this->payload;
        }

        // 1. Ensure inputs are arrays (Prevents errors if null)
        $payload = (array) ($this->payload ?? []);
        $allowedFields = (array) ($form->fields_needed ?? []);

        // 2. Extract only keys that exist in both (The "Speedy" Hack)
        // $sanitizedData = array_intersect_key($payload, $allowedFields);

        return keys_only($payload, array_keys($allowedFields));
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('created_at');
    }

    public function jobs()
    {
        // This looks for 'target_id' and 'target_type' directly on the 'ai_jobs' table.
        return $this->morphMany(AiJob::class, 'target');

        // We cast the ID to a string to satisfy Postgres's strict typing
        // return $this->morphMany(AiJob::class, 'target')
        //     ->whereRaw('target_id::text = ?', [(string)$this->id]);
    }

    public function latestJob()
    {
        // morphOne is the "single" version of morphMany
        return $this->morphOne(AiJob::class, 'target')->latestOfMany('started_at');
    }
}
