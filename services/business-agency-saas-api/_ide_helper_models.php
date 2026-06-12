<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property int $ai_agent_id
 * @property string $event_class
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $conditions
 * @property-read \App\Models\AiAgent $aiAgent
 * @property-read \App\Models\Tenant $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereAiAgentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereConditions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereEventClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgentTrigger whereUpdatedAt($value)
 */
	class AgentTrigger extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $brain Service provider key, e.g., "openai", "anthropic"
 * @property string $model Specific model identifier, e.g., "gpt-4o"
 * @property string|null $system_prompt
 * @property string|null $user_prompt Supports placeholders like {{variable}}
 * @property array<array-key, mixed>|null $tools
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $handler_class
 * @property int $context_window_size
 * @property array<array-key, mixed>|null $tool_configs
 * @property-read mixed $integration
 * @property-read \App\Models\Tenant $tenant
 * @property-read \App\Models\AgentTrigger|null $trigger
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereBrain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereContextWindowSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereHandlerClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereModel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereSystemPrompt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereToolConfigs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereTools($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiAgent whereUserPrompt($value)
 */
	class AiAgent extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $avatar_url
 * @property string|null $welcome_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $ai_agent_id
 * @property-read \App\Models\AiAgent|null $agent
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereAiAgentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereWebhookSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereWebhookUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChat whereWelcomeMessage($value)
 */
	class AiChat extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $job_uuid
 * @property string $agent_slug
 * @property int|null $target_id
 * @property string|null $target_type
 * @property string $status
 * @property int $attempts
 * @property array<array-key, mixed> $payload
 * @property array<array-key, mixed>|null $result
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $friendly_status
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent|null $target
 * @property-read \App\Models\Tenant $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereAgentSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereJobUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereTargetType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiJob whereUpdatedAt($value)
 */
	class AiJob extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string|null $api_key_id
 * @property string $method
 * @property string $route
 * @property int $status_code
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<array-key, mixed>|null $payload
 * @property float|null $duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Tenant|null $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereApiKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereRoute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereStatusCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiAuditLog whereUserId($value)
 */
	class ApiAuditLog extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $ai_chat_id
 * @property int $user_id
 * @property string $role
 * @property string|null $content
 * @property array<array-key, mixed>|null $files
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereAiChatId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereFiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChatMessage whereUserId($value)
 */
	class ChatMessage extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property array<array-key, mixed> $schema
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $form_source
 * @property string|null $ref_form_id
 * @property string|null $form_public_url
 * @property array<array-key, mixed>|null $fields_needed
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Webhook> $webhooks
 * @property-read int|null $webhooks_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereFieldsNeeded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereFormPublicUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereFormSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereRefFormId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereSchema($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Form whereUpdatedAt($value)
 */
	class Form extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string|null $email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $location_id
 * @property string|null $location_name
 * @property string|null $business_name
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereAccessToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereBusinessName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereLocationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereRefreshToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GoogleAccount whereUpdatedAt($value)
 */
	class GoogleAccount extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $service
 * @property string $key
 * @property array<array-key, mixed> $value
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_brain
 * @property-read \App\Models\Tenant $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereIsBrain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereService($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integration whereValue($value)
 */
	class Integration extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string|null $form_id
 * @property array<array-key, mixed> $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $source
 * @property string $temperature
 * @property string $status
 * @property array<array-key, mixed>|null $meta_data
 * @property string|null $notes
 * @property string $insert_method
 * @property int $score
 * @property bool $won
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LeadActivity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\Form|null $form
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AiJob> $jobs
 * @property-read int|null $jobs_count
 * @property-read \App\Models\AiJob|null $latestJob
 * @property-read \App\Models\Tenant|null $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereFormId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereInsertMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereMetaData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereTemperature($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lead whereWon($value)
 */
	class Lead extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $lead_id
 * @property string $type
 * @property string $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $metadata
 * @property-read \App\Models\Lead $lead
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereLeadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadActivity whereUpdatedAt($value)
 */
	class LeadActivity extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property int $lead_id
 * @property int $tenant_id
 * @property string $platform
 * @property string $platform_user_id
 * @property string $status
 * @property \Illuminate\Support\Carbon $last_interaction_at
 * @property string|null $thread_id
 * @property array<array-key, mixed>|null $context_data
 * @property int $message_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Lead $lead
 * @property-read \App\Models\Tenant|null $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereContextData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereLastInteractionAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereLeadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereMessageCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession wherePlatformUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LeadChatSession whereUpdatedAt($value)
 */
	class LeadChatSession extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Plan> $plans
 * @property-read int|null $plans_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereUpdatedAt($value)
 */
	class Module extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property numeric $price
 * @property int $ai_credit_limit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Module> $modules
 * @property-read int|null $modules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant> $tenants
 * @property-read int|null $tenants_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereAiCreditLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereUpdatedAt($value)
 */
	class Plan extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $domain
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $ai_limit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Plan> $plans
 * @property-read int|null $plans_count
 * @property-read \App\Models\TenantSetting|null $settings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 */
	class Tenant extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $tenant_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $crm_config
 * @property string $ai_provider_default
 * @property-read \App\Models\Tenant|null $tenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereAiProviderDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereCrmConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereUpdatedAt($value)
 */
	class TenantSetting extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $current_tenant_id
 * @property-read \App\Models\Tenant|null $currentTenant
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant> $tenants
 * @property-read int|null $tenants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCurrentTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $tenant_id
 * @property string|null $name
 * @property string $url
 * @property string|null $secret
 * @property array<array-key, mixed>|null $events
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $form_id
 * @property-read \App\Models\Form|null $form
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereEvents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereFormId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Webhook whereUrl($value)
 */
	class Webhook extends \Eloquent {}
}

