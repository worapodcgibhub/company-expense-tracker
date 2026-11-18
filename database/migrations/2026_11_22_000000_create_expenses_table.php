<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off expenses (Phase One-off 1). Purely additive: creates one new table
 * and does not change any existing table or row.
 *
 * - `expenses`: one row per payment that is not a recurring subscription
 *   (equipment, repairs, travel ...). Separate from `subscription_charges`,
 *   so subscription totals and history are never affected.
 * - `paid_on` is the day it was really paid; its month is the accounting
 *   period (YYYY-MM) checked against `accounting_period_closures`.
 * - `amount` is in minor units of `currency`. For USD, `amount_thb` is the
 *   baht really paid and stays NULL until it is known: no exchange rate is
 *   ever assumed, and USD is never added to baht.
 * - `owner_user_id` is the person the expense belongs to (what Staff may
 *   see and edit); `recorded_by` / `updated_by` are who entered / changed it.
 * - Receipts (mini version): `receipt_received_at` is set when the document
 *   has been received, NULL means "waiting for receipt". Uploading files
 *   comes in a later phase.
 * - Never deleted by the app (restrict on delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('category', 20);
            $table->date('paid_on');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_thb')->nullable();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('receipt_received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('paid_on');
            $table->index(['owner_user_id', 'paid_on']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
