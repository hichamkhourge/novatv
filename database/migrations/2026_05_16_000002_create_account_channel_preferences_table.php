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
        Schema::create('account_channel_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('iptv_accounts')
                ->onDelete('cascade');
            $table->foreignId('channel_id')
                ->constrained('channels')
                ->onDelete('cascade');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Ensure one preference per account-channel pair
            $table->unique(['account_id', 'channel_id']);

            // Index for faster queries
            $table->index('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_channel_preferences');
    }
};
