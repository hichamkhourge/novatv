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
        Schema::create('connection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_user_id')
                ->constrained('iptv_users')
                ->onDelete('cascade');
            $table->foreignId('channel_id')
                ->nullable()
                ->constrained('channels')
                ->onDelete('set null');
            $table->string('ip_address', 45); // Support IPv6
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Index for dashboard stats and filtering
            $table->index(['iptv_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_logs');
    }
};
