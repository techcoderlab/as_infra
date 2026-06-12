<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FormController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Form::class);

        return Form::with([
            'webhooks' => fn ($hooks) => $hooks->select('id', 'form_id', 'name', 'url', 'is_active'),
        ])->orderByDesc('created_at')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Form::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'schema' => ['sometimes', 'nullable', 'array'],
            'form_source' => ['required', 'string', 'max:20', 'in:system,tally,typeform,wordpressform'],
            'form_public_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'ref_form_id' => ['sometimes', 'nullable', 'string', 'max:200'],
            'fields_needed' => ['sometimes', 'nullable', 'array'],
        ]);

        $form = Form::create([
            'name' => $validated['name'],
            'schema' => ! empty($validated['schema']) ? $validated['schema'] : [],
            'is_active' => $validated['is_active'] ?? true,
            'form_source' => $validated['form_source'],
            'form_public_url' => $validated['form_public_url'] ?? null,
            'ref_form_id' => $validated['ref_form_id'] ?? null,
            'fields_needed' => $validated['fields_needed'] ?? null,
        ]);

        return response()->json($form, Response::HTTP_CREATED);
    }

    public function update(Request $request, Form $form)
    {
        $this->authorize('update', $form);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'schema' => ['sometimes', 'nullable', 'array'],
            'form_source' => ['required', 'string', 'max:20', 'in:system,tally,typeform,wordpressform'],
            'form_public_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'ref_form_id' => ['sometimes', 'nullable', 'string', 'max:200'],
            'fields_needed' => ['sometimes', 'nullable', 'array'],
        ]);

        $validated['schema'] = ! empty($validated['schema']) ? $validated['schema'] : [];

        $form->fill($validated);
        $form->save();
        $form->clearCache();

        return response()->json($form);
    }

    public function destroy(Request $request, Form $form)
    {
        $this->authorize('delete', $form);

        $form->delete();
        $form->clearCache();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
