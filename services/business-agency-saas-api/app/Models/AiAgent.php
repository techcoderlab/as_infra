<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiAgent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'brain',
        'model',
        'system_prompt',
        'user_prompt',
        'tools',
        'tool_configs',
        'handler_class',
        'is_active',
    ];

    protected $casts = [
        'tools' => 'array',
        'tool_configs' => 'encrypted:array',
        'is_active' => 'boolean',
    ];

    /**
     * Mutator to handle mixed tool sets (Strings + Config Arrays).
     * Splits into 'tools' (names) and 'tool_configs' (encrypted secrets).
     */
    public function setToolsAttribute($value)
    {
        $cleanTools = [];
        $newConfigs = [];

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                // If Item is Array, Key must be Tool Name
                if (is_array($item)) {
                    $toolName = is_string($key) ? $key : null;
                    if ($toolName) {
                        $cleanTools[] = $toolName;
                        $newConfigs[$toolName] = $item;
                    }
                }

                // If Item is String, it's just a Tool Name
                elseif (is_string($item)) {
                    /* If $item is in integrations table, add it to cleanTools and its value to newConfigs */
                    // $toolName = strtolower(trim($item));
                    $toolName = strtolower(trim(Str::before($item, '_')));

                    if (! empty($toolName)) {

                        $integration = Integration::where('tenant_id', $this->tenant_id)
                            ->where('service', 'like', '%'.$toolName.'%')
                            ->where('is_active', true)
                            ->first();

                        if ($integration) {
                            $newConfigs[$item] = $integration->value;
                        }
                    }

                    $cleanTools[] = $item;
                }
            }
        } else {
            // Handle raw JSON string or null
            $this->attributes['tools'] = $value;

            return;
        }

        // 1. Save strictly the list of names
        $this->attributes['tools'] = json_encode($cleanTools);

        // 2. If configs were passed, save them to the encrypted column
        if (! empty($newConfigs)) {
            $this->tool_configs = $newConfigs;
            \Illuminate\Support\Facades\Log::info('AiAgent: Auto-discovered configs for tools: '.json_encode(array_keys($newConfigs)));
        } else {
            $this->tool_configs = null;
            \Illuminate\Support\Facades\Log::warning('AiAgent: No tool configs found or auto-discovered.');
        }
    }

    /**
     * Relationship: Belongs to a Tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Resolves the Integration record for this Agent's specific "brain" (service).
     * * Usage: $agent->integration
     * Returns: Integration model instance or null.
     */
    public function getIntegrationAttribute()
    {
        // We cannot use a standard belongsTo/hasOne relationship here easily
        // because the foreign key is a string ('brain' -> 'service')
        // AND it depends on the matching tenant_id.

        return Integration::where('tenant_id', $this->tenant_id)
            ->where('service', trim($this->brain))
            ->where('is_active', true)
            ->first();
    }

    public function trigger()
    {
        return $this->hasOne(AgentTrigger::class);
    }

    /**
     * Helper to check if the agent is ready for execution.
     */
    public function isReady(): bool
    {
        return $this->is_active && $this->integration !== null;
    }

    // /**
    //  * Replaces {{variable}} in the text with values from the model.
    //  */
    // public function hydratePrompt(array $data): string
    // {
    //     if (empty($data) || ! is_array($data)) {
    //         // throw new \InvalidArgumentException('BUILDING USER PROMPT: Data is not an array or null.');
    //         return '';
    //     }

    //     if (empty($this->user_prompt)) {
    //         return '';
    //     }

    //     return preg_replace_callback('/\{\{(.*?)\}\}/', function ($matches) use ($data) {
    //         $key = trim($matches[1]);

    //         // Handle dot notation if needed, e.g., {{ user.name }}
    //         return data_get($data, $key, '');
    //     }, $this->user_prompt);
    // }

    /**
     * Replaces {{variable}} in the text with values from the model.
     * SECURITY FIX: Implements "Tagging" and Sanitization to prevent Prompt Injection.
     */
    public function hydratePrompt(array $data = []): string
    {
        if (empty($this->user_prompt)) {
            return '';
        }

        if (empty($data)) {
            return $this->user_prompt."\n\n";
        }

        return preg_replace_callback('/\{\{(.*?)\}\}/', function ($matches) use ($data) {
            $key = trim($matches[1]);
            $value = data_get($data, $key, '');

            if (is_array($value)) {
                $value = json_encode($value);
            }

            // 1. Sanitize: Remove sequences that mimic System/User turns (Common in ChatML/OpenAI)
            // This prevents "Role Hijacking"
            $safeValue = str_ireplace(
                ['<|system|>', '<|user|>', '<|assistant|>', 'System:', 'User:'],
                '',
                (string) $value
            );

            // 2. Wrap: If the value is long or complex, wrap it in XML tags to delineate data from instructions.
            // This is the "Delimited Prompting" defense.
            if (strlen($safeValue) > 50) {
                return "\n<user_data_for_{$key}>\n".$safeValue."\n</user_data_for_{$key}>\n";
            }

            return $safeValue;
        }, $this->user_prompt);
    }
}
