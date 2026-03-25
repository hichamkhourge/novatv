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
        Schema::create('user_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_user_id')->constrained('iptv_users')->cascadeOnDelete();
            $table->foreignId('m3u_source_id')->constrained('m3u_sources')->cascadeOnDelete();
            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending');
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Index for querying logs by user or source
            $table->index(['iptv_user_id', 'created_at']);
            $table->index(['m3u_source_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_automation_logs');
    }
};
