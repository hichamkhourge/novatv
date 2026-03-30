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
        // Drop pivot table first (has foreign keys)
        Schema::dropIfExists('package_channel_group');

        // Drop main tables
        Schema::dropIfExists('packages');
        Schema::dropIfExists('channel_groups');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate tables in reverse order
        Schema::create('channel_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('max_connections')->default(1);
            $table->integer('duration_days');
            $table->decimal('price', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('package_channel_group', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('channel_group_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->primary(['package_id', 'channel_group_id']);
        });
    }
};
