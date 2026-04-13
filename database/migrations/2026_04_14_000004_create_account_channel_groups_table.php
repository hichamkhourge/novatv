<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_channel_groups', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained('iptv_accounts')->cascadeOnDelete();
            $table->foreignId('channel_group_id')->constrained('channel_groups')->cascadeOnDelete();
            $table->primary(['account_id', 'channel_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_channel_groups');
    }
};
