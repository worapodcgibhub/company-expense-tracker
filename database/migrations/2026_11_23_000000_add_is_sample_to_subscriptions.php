<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells sample data apart from real data (kept on purpose for trying the
 * system out).
 *
 * - Adds ONE plain column to `subscriptions`: `is_sample` (default false).
 *   ADD COLUMN only: the table is not rebuilt and no row is deleted.
 * - Flags the subscriptions the sample-data seeder created, which carry its
 *   exact marker text in `notes`. Only `is_sample` is written; nothing else
 *   on those rows changes, and the marker text stays where it is.
 * - Charges, documents and history of a sample subscription are sample data
 *   too: they follow their subscription (subscription_id), so they need no
 *   column of their own.
 * - down() drops the column again (the data rows are untouched).
 */
return new class extends Migration
{
    /** Same text as SampleDataSeeder::MARKER (copied so this migration never changes). */
    private const MARKER = 'ข้อมูลตัวอย่าง (Sample data)';

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false);
        });

        DB::table('subscriptions')->where('notes', self::MARKER)->update(['is_sample' => true]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_sample');
        });
    }
};
