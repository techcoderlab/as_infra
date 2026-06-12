<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->string('location_id')->nullable()->after('email');
            $table->string('location_name')->nullable()->after('location_id'); // e.g. accounts/123/locations/456
            $table->string('business_name')->nullable()->after('location_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            //
        });
    }
};
