<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts and tax invoices for actual charges (Phase 2.5).
 *
 * - Creates `charge_receipts`: one row per uploaded file. Files live on the
 *   private disk; the row keeps the random stored path, the real MIME type,
 *   size and SHA-256. Nothing is ever deleted: removing or replacing a
 *   document only sets `removed_at` (who / why), and the file stays.
 *   `document_date` is the date printed on the document, kept apart from
 *   the charge date because the two often differ.
 * - Adds three NULLABLE columns to `subscription_charges` for "no receipt
 *   available" (who, when, why) with plain ALTER TABLE ADD COLUMN — the
 *   existing table is not rebuilt and existing rows are not changed (null).
 *   A charge with no document and no such note is "waiting for receipt".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_charge_id')->constrained()->restrictOnDelete();
            $table->string('kind', 15);
            $table->string('document_number', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->string('original_name', 255);
            $table->string('disk', 20);
            $table->string('path', 255)->unique();
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64)->index();
            $table->foreignId('replaces_id')->nullable()->constrained('charge_receipts')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remove_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['subscription_charge_id', 'removed_at']);
        });

        // Plain nullable columns only. No foreign key here on purpose: adding
        // one to an existing SQLite table makes Laravel rebuild the table
        // (copy, DROP, rename). Plain ADD COLUMN never touches existing rows.
        // Users are never deleted (only deactivated), so the id stays valid.
        Schema::table('subscription_charges', function (Blueprint $table) {
            $table->timestamp('receipt_unavailable_at')->nullable();
            $table->unsignedBigInteger('receipt_unavailable_by')->nullable();
            $table->string('receipt_unavailable_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_charges', function (Blueprint $table) {
            $table->dropColumn(['receipt_unavailable_at', 'receipt_unavailable_by', 'receipt_unavailable_reason']);
        });

        Schema::dropIfExists('charge_receipts');
    }
};
