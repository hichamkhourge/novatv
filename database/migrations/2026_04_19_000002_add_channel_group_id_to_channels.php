<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('channel_group_id')
                ->nullable()
                ->constrained('channel_groups')
                ->onDelete('set null');
            $table->integer('sort_order')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['channel_group_id']);
            $table->dropColumn(['channel_group_id', 'sort_order']);
        });
    }
};