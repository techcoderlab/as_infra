<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the old partial unique index (using raw SQL because it contains a WHERE clause)
        DB::statement('DROP INDEX IF EXISTS episodic_facts_unique_active;');

        Schema::table('episodic_facts', function (Blueprint $table) {
            // 2. Drop the old foreign key and column
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');

            // 3. Add polymorphic columns
            // Using string for target_id allows flexibility for both Integer IDs and Session UUIDs
            $table->string('target_type')->nullable()->index()->after('tenant_id');
            $table->string('target_id')->nullable()->index()->after('target_type');
        });

        // 4. Re-create the partial unique index using the new polymorphic columns
        DB::statement('CREATE UNIQUE INDEX episodic_facts_unique_active ON episodic_facts (tenant_id, target_type, target_id, fact_type) WHERE superseded_at IS NULL;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Drop the new unique index
        DB::statement('DROP INDEX IF EXISTS episodic_facts_unique_active;');

        Schema::table('episodic_facts', function (Blueprint $table) {
            // 2. Drop the polymorphic columns
            $table->dropColumn(['target_type', 'target_id']);

            // 3. Re-add the lead_id column and its foreign key
            $table->unsignedBigInteger('lead_id')->nullable()->index()->after('tenant_id');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
        });

        // 4. Re-create the old partial unique index
        DB::statement('CREATE UNIQUE INDEX episodic_facts_unique_active ON episodic_facts (tenant_id, lead_id, fact_type) WHERE superseded_at IS NULL;');
    }
};