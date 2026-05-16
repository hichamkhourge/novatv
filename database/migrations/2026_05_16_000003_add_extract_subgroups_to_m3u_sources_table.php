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
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->boolean('extract_subgroups')
                ->default(false)
                ->after('excluded_groups')
                ->comment('Extract sub-groups from channel name prefixes (e.g., "AR Morocco - Channel" → group "AR Morocco")');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn('extract_subgroups');
        });
    }
};
