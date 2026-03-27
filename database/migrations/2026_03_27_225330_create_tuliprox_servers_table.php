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
        Schema::create('tuliprox_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('protocol')->default('http'); // http or https
            $table->string('host');
            $table->string('port')->default('8901');
            $table->string('timezone')->default('UTC');
            $table->text('message')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Only one default server
            $table->index(['is_default', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tuliprox_servers');
    }
};
