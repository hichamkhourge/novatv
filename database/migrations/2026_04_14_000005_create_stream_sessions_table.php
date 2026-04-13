<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old stream_sessions table if it exists (different schema)
        Schema::dropIfExists('stream_sessions');

        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('iptv_accounts')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('ip_address');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            // Unique session per account + channel + IP
            $table->unique(['account_id', 'channel_id', 'ip_address']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_sessions');
    }
};
