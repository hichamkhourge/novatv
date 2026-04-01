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
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->text('tvg_logo')->nullable();
            $table->string('group_name')->nullable()->index();
            $table->text('stream_url');
            $table->foreignId('m3u_source_id')->constrained()->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Index for faster filtering
            $table->index(['m3u_source_id', 'is_active']);
            $table->index(['group_name', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
