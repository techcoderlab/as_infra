<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // 1. Remove obsolete fields
            $table->dropColumn(['webhook_url', 'webhook_secret']);

            // 2. Add new fields with robust typing
            // We use nullable() for ref_form_id to support legacy internal forms
            $table->string('form_source')->default('system')->after('name');
            $table->string('ref_form_id')->nullable()->after('form_source');
            $table->string('form_public_url', 500)->nullable()->after('ref_form_id');

            // Optional: Indexing for fast lookups during webhook processing
            $table->index(['form_source', 'ref_form_id']);
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // Reverse the changes for safe rollbacks
            $table->dropIndex(['form_source', 'ref_form_id']);
            $table->dropColumn(['form_source', 'ref_form_id', 'form_public_url']);

            // Restore original fields
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
        });
    }
};
