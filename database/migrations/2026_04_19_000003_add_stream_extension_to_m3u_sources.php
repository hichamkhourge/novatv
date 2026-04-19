<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('m3u_sources', 'stream_extension')) {
                $table->string('stream_extension')->default('ts')->after('source_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn('stream_extension');
        });
    }
};