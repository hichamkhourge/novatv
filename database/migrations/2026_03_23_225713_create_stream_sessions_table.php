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
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_user_id')->constrained('iptv_users')->cascadeOnDelete();
            $table->string('ip_address');
            $table->text('user_agent')->nullable();
            $table->string('stream_id');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stream_sessions');
    }
};
