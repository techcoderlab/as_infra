<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update tenant_settings (Your Wide Table)
        Schema::table('tenant_settings', function (Blueprint $table) {
            // One column for all encrypted keys/configs
            // if (!Schema::hasColumn('tenant_settings', 'api_creds')) {
            //     $table->text('api_creds')->nullable();
            // }
            // if (!Schema::hasColumn('tenant_settings', 'ai_enabled')) {
            //     $table->boolean('ai_enabled')->default(false);
            // }
            if (! Schema::hasColumn('tenant_settings', 'ai_provider_default')) {
                $table->string('ai_provider_default')->default('openai');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'ai_credit_limit')) {
                $table->unsignedBigInteger('ai_credit_limit')->default(0)->after('price');
            }
        });

        // 2. Update plan_tenant pivot table
        Schema::table('plan_tenant', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_tenant', 'ai_credits_used')) {
                $table->unsignedInteger('ai_credits_used')->default(0);
            }
        });

        // 3. Update lead_activities for JSON metadata
        Schema::table('lead_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('lead_activities', 'metadata')) {
                $table->jsonb('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Schema::table('tenant_settings', function (Blueprint $table) {
        //     $table->dropColumn(['api_creds', 'ai_enabled', 'ai_provider_default']);
        // });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_credit_limit');
        });

        Schema::table('plan_tenant', function (Blueprint $table) {
            $table->dropColumn('ai_credits_used');
        });

        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
