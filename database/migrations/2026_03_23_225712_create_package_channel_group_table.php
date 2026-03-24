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
        Schema::create('package_channel_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('channel_group_id')->constrained('channel_groups')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_channel_group');
    }
};
