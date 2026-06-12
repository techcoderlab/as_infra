<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update Forms Table
        Schema::table('forms', function (Blueprint $table) {
            // Rename specific N8N url to generic webhook_url
            if (Schema::hasColumn('forms', 'n8n_webhook_url')) {
                $table->renameColumn('n8n_webhook_url', 'webhook_url');
            } else {
                $table->string('webhook_url')->nullable();
            }
            $table->string('webhook_secret')->nullable()->after('schema');
        });

        // 2. Update Leads Table
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source')->default('form')->after('form_id'); // e.g., linkedIn, google_maps, yellowpage, form
            $table->string('temperature')->default('cold')->after('source'); // cold, warm, hot
            $table->string('status')->default('new')->after('temperature'); // new, contacted, closed
            $table->jsonb('meta_data')->nullable()->after('payload');
            $table->text('notes')->nullable()->after('meta_data');

            // Make form_id nullable for manual leads
            $table->uuid('form_id')->nullable()->change();
        });

        // 3. Create Lead Activities Table
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index(); // system, note, status_change
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['source', 'temperature', 'status', 'meta_data', 'notes']);
            $table->uuid('form_id')->nullable(false)->change();
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->renameColumn('webhook_url', 'n8n_webhook_url');
            $table->dropColumn('webhook_secret');
        });
    }
};
