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
        // Drop table if it exists with old schema (no id column)
        Schema::dropIfExists('user_sources');

        Schema::create('user_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_user_id')
                ->constrained('iptv_users')
                ->onDelete('cascade');
            $table->foreignId('m3u_source_id')
                ->constrained('m3u_sources')
                ->onDelete('cascade');
            $table->timestamps();

            // Ensure each user can only be linked to a source once
            $table->unique(['iptv_user_id', 'm3u_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sources');
    }
};
