<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->jsonb('enabled_modules')
                ->after('status')
                ->default(json_encode(['leads', 'forms', 'webhooks', 'ai-chats', 'api-keys']));
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('enabled_modules');
        });
    }
};
