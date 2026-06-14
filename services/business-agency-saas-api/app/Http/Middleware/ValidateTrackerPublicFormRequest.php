<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateTrackerPublicFormRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $formId = $request->input('data.formId') ?? $request['uuid'] ?? null;
        $formIdKey = $request->has('data.formId') ? 'ref_form_id' : 'id';
        $BOT_DETECTION_THRESHOLD_MS = 2500;
        $HONEYPOT_FIELD = 'company_name_verification';
        $CACHE_KEY = "form_tracker_{$formId}";
        $CACHE_TTL = 3600;

        // 1. QUICK EXIT: No Form ID
        if (! $formId) {
            Log::error('Form ID is required', ['request' => $request->all()]);

            return response()->json(['message' => 'Unprocessable Entity. Form ID is required.'], 422);
        }

        // 2. SPEEDY DB LOOKUP: Fetch only needed columns
        // Create a unique key for this specific form
        $form = Cache::remember($CACHE_KEY, $CACHE_TTL, function () use ($formIdKey, $formId) {
            return \DB::table('forms')
                ->select('form_public_url', 'is_active')
                ->where($formIdKey, $formId)
                ->first();
        });

        if (! $form || ! $form->is_active) {
            Log::error('Form Not found or closed', [
                'form_id' => $formId,
                'form' => $form,
            ]);

            return response()->json(['message' => 'Form is not found or closed.'], 400);
        }

        // 3. BOT CHECK (Honeypot & Timing)
        $hp = $request->input('data.fields.'.$HONEYPOT_FIELD) ?? $request->input($HONEYPOT_FIELD) ?? null;
        $ms = $request->input('data.ms_since_load') ?? $request->input('ms_since_load') ?? $BOT_DETECTION_THRESHOLD_MS + 1;

        if (! empty($hp) || (int) $ms < $BOT_DETECTION_THRESHOLD_MS) {
            Log::error("Bot blocked: Form $formId | MS: $ms");

            return response()->json(['status' => 'success', 'meta' => 'hc'], 200); // Fake success
        }

        // 4. DOMAIN LOCK (Dynamic & Native PHP)
        $requestOrigin = $request->headers->get('origin') ?? $request->headers->get('referer');

        if ($requestOrigin && $form->form_public_url) {
            if (! is_valid_origin($form->form_public_url, $requestOrigin)) {
                Log::error("Origin Mismatch: Form $formId | Expected: {$form->form_public_url} | Got: $requestOrigin");

                return response()->json(['message' => 'Unauthorized action'], 403);
            }

            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized action'], 403);
    }
}
