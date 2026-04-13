<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds sort_order and is_active to the existing channel_groups table.
 * (slug was already added in the 2026_03_23 original migration)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_groups', function (Blueprint $table) {
            $existing = Schema::getColumnListing('channel_groups');

            if (! in_array('sort_order', $existing)) {
                $table->unsignedInteger('sort_order')->default(0)->after('slug');
            }

            if (! in_array('is_active', $existing)) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channel_groups', function (Blueprint $table) {
            $existing = Schema::getColumnListing('channel_groups');

            if (in_array('sort_order', $existing)) {
                $table->dropColumn('sort_order');
            }
            if (in_array('is_active', $existing)) {
                $table->dropColumn('is_active');
            }
        });
    }
};
