<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change logo_url from varchar(2048) to text to handle:
     * - Very long CDN URLs with query parameters
     * - Base64-encoded images
     * - URLs with authentication tokens
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Check if logo_url column exists before modifying it
            if (Schema::hasColumn('channels', 'logo_url')) {
                $table->text('logo_url')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (Schema::hasColumn('channels', 'logo_url')) {
                $table->string('logo_url', 2048)->nullable()->change();
            }
        });
    }
};
