<?php

// ─────────────────────────────────────────────────────
// Module   : ExternalApiKeyController
// ─────────────────────────────────────────────────────
// Pillar Compliance:
// [x] P0 Stack Bootstrap (PHP 8.2+, Laravel)
// [x] P1 Architecture (Delegates complex logic, handles HTTP)
// [x] P2 Security (Validates inputs, revokes tokens)
// [x] P6 Resilience (Handles errors gracefully)
// [x] P8 Code Quality (Strict typing, clear names)

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ExternalApiKeyController extends Controller
{
    /**
     * List all external API keys.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // TODO: Ensure this is protected by super-admin middleware
        $keys = ExternalApiKey::select(['id', 'app_id', 'for', 'is_active', 'created_at', 'updated_at'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'keys' => $keys,
        ]);
    }

    /**
     * Generate a new external API key.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'for' => 'required|string|max:255',
        ]);

        $appId = 'app_'.bin2hex(random_bytes(8));
        $plaintextSecret = 'sk_live_'.bin2hex(random_bytes(16));

        try {
            DB::beginTransaction();

            $apiKey = ExternalApiKey::create([
                // 'user_id' => $request->user()->id ?? null,
                'app_id' => $appId,
                'secret' => encrypt($plaintextSecret), // P2: Encrypted at rest
                'for' => $validated['for'],
                'is_active' => true,
            ]);

            // Push to Redis (PLAINTEXT)
            // P4: Distributed Cache for ultra-fast Sidecar lookup
            Redis::set("apikey:{$appId}", $plaintextSecret);

            DB::commit();

            return response()->json([
                'message' => 'API Key generated successfully',
                'entry' => $apiKey,
                'token' => $plaintextSecret, // Only shown once
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ExternalApiKeyController] Failed to generate key: '.$e->getMessage());

            return response()->json(['error' => 'Failed to generate API Key'], 500);
        }
    }

    /**
     * Revoke and delete an external API key.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(string $id)
    {
        $key = ExternalApiKey::findOrFail($id);

        try {
            DB::beginTransaction();

            // Remove from Redis cache first
            Redis::del("apikey:{$key->app_id}");

            // Remove from Database
            $key->delete();

            DB::commit();

            return response()->json(['message' => 'API Key revoked successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ExternalApiKeyController] Failed to revoke key: '.$e->getMessage());

            return response()->json(['error' => 'Failed to revoke API Key'], 500);
        }
    }

    /**
     * Rotate an external API key.
     * Generates a new secret for the same App ID.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function rotate(string $id)
    {
        $key = ExternalApiKey::findOrFail($id);
        $newPlaintextSecret = 'sk_live_'.bin2hex(random_bytes(16));

        try {
            DB::beginTransaction();

            $key->update([
                'secret' => encrypt($newPlaintextSecret),
            ]);

            // Overwrite in Redis
            Redis::set("apikey:{$key->app_id}", $newPlaintextSecret);

            DB::commit();

            return response()->json([
                'message' => 'API Key rotated successfully',
                'entry' => $key,
                'token' => $newPlaintextSecret, // Only shown once
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ExternalApiKeyController] Failed to rotate key: '.$e->getMessage());

            return response()->json(['error' => 'Failed to rotate API Key'], 500);
        }
    }
}
