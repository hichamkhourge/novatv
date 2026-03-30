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
        // Drop existing channels table (clean slate)
        Schema::dropIfExists('channels');

        // Recreate with new schema optimized for Xtream API
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('m3u_source_id')
                ->constrained('m3u_sources')
                ->onDelete('cascade');
            $table->integer('stream_id')->nullable();
            $table->string('name');
            $table->text('stream_url');
            $table->string('logo')->nullable();
            $table->string('category')->nullable();
            $table->string('epg_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // Performance indexes for Xtream API queries
            $table->index(['m3u_source_id', 'stream_url'], 'channels_source_url_idx');
            $table->index(['m3u_source_id', 'is_active'], 'channels_source_active_idx');
            $table->index('category', 'channels_category_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop new table
        Schema::dropIfExists('channels');

        // Recreate old schema (from exploration report)
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->string('tvg_logo')->nullable();
            $table->string('group_name')->nullable();
            $table->text('stream_url');
            $table->foreignId('m3u_source_id')
                ->constrained('m3u_sources')
                ->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Old indexes
            $table->index('group_name');
            $table->index(['m3u_source_id', 'is_active']);
            $table->index(['group_name', 'is_active']);
        });
    }
};
