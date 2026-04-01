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
        // Drop tuliprox-related pivot table
        Schema::dropIfExists('iptv_user_channel');

        // Drop tuliprox automation logs
        Schema::dropIfExists('user_automation_logs');

        // Drop tuliprox servers table
        Schema::dropIfExists('tuliprox_servers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate tuliprox_servers table
        Schema::create('tuliprox_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('protocol')->default('http');
            $table->string('host');
            $table->integer('port')->default(8901);
            $table->string('timezone')->default('UTC');
            $table->text('message')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Recreate automation logs table
        Schema::create('user_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_user_id')->constrained()->onDelete('cascade');
            $table->foreignId('m3u_source_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status');
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Recreate iptv_user_channel pivot table
        Schema::create('iptv_user_channel', function (Blueprint $table) {
            $table->foreignId('iptv_user_id')->constrained()->onDelete('cascade');
            $table->foreignId('channel_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->primary(['iptv_user_id', 'channel_id']);
        });
    }
};
