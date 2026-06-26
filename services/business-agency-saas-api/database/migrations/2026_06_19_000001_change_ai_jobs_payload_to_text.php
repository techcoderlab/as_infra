<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────
// Module   : ChangeAiJobsPayloadToText
// ─────────────────────────────────────────────────────
// The `payload` column was jsonb, but the AiJob model now uses
// `encrypted:array` cast (P2 - Security). Laravel's encryption
// produces a base64 ciphertext string, which is invalid JSON.
// We must change the column type from jsonb → text to store it.

return new class extends Migration
{
    public function up(): void
    {
        // Convert existing jsonb data to text first, then alter the type.
        DB::statement('ALTER TABLE ai_jobs ALTER COLUMN payload TYPE text USING payload::text');
    }

    public function down(): void
    {
        // Reversing requires valid JSON in the column; this is a best-effort rollback.
        DB::statement('ALTER TABLE ai_jobs ALTER COLUMN payload TYPE jsonb USING payload::jsonb');
    }
};
