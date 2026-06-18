<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
// Assuming this trait handles tenant scoping
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Webhook::class);

        // Fetch webhooks for current tenant
        return Webhook::where('tenant_id', $request->user()->current_tenant_id)
            ->with('form:id,name') // Eager load form name if relation exists
            // ->orderBy('created_at', 'desc')
            ->orderByRaw('created_at DESC NULLS LAST') // for postgres
            ->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Webhook::class);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'url' => 'required|url',
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'secret' => 'nullable|string|max:255',
            // Validate form_id exists and belongs to the current tenant
            'form_id' => [
                'nullable',
                'string',
                Rule::exists('forms', 'id')->where(function ($query) use ($request) {
                    return $query->where('tenant_id', $request->user()->current_tenant_id);
                }),
            ],
            'events' => 'required|array',
            'events.*' => 'string',
        ]);

        $webhook = new Webhook($validated);
        $webhook->tenant_id = $request->user()->current_tenant_id;
        $webhook->is_active = true;
        // form_id is fillable via $validated if added to $fillable in Model,
        // but explicitly setting it guarantees it saves if $guarded is used.
        $webhook->form_id = $validated['form_id'] ?? null;
        $webhook->save();

        return response()->json($webhook, 201);
    }

    public function update(Request $request, $id)
    {
        $webhook = Webhook::where('tenant_id', $request->user()->current_tenant_id)
            ->where('id', $id)
            ->firstOrFail();

        $this->authorize('update', $webhook);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'url' => 'required|url',
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'secret' => 'nullable|string|max:255',
            'form_id' => [
                'nullable',
                'string',
                Rule::exists('forms', 'id')->where(function ($query) use ($request) {
                    return $query->where('tenant_id', $request->user()->current_tenant_id);
                }),
            ],
            'events' => 'required|array',
            'events.*' => 'string',
            'is_active' => 'boolean',
        ]);

        // Explicitly map properties
        $webhook->fill($validated);
        $webhook->form_id = $validated['form_id'] ?? $webhook->form_id;
        $webhook->save();

        return response()->json($webhook);
    }

    public function destroy(Request $request, $id)
    {
        $webhook = Webhook::where('tenant_id', $request->user()->current_tenant_id)
            ->where('id', $id)
            ->firstOrFail();

        $this->authorize('delete', $webhook);

        $webhook->delete();

        return response()->noContent();
    }
}
