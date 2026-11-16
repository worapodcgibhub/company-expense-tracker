<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual charges (Phase 2.4). Purely additive: creates three new tables and
 * does not change any existing table or row.
 *
 * - `subscription_charges`: one row per billing cycle of a subscription
 *   (unique subscription_id + due_date, so a cycle can never be recorded
 *   twice). `amount` is in minor units of `currency`; for USD charges
 *   `amount_thb` is what the bank actually took in baht (filled in later
 *   when the card statement arrives). Rows are never deleted.
 * - `subscription_charge_revisions`: append-only history of every change
 *   (recorded / updated / verified / unverified), who and when.
 * - `accounting_period_closures`: append-only log of closing and reopening
 *   accounting months (YYYY-MM). The latest row of a period is its state.
 *   `snapshot` keeps the figures as they were at closing, so later entries
 *   never change a closed period automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->restrictOnDelete();
            $table->date('due_date');
            $table->string('outcome', 10);
            $table->date('charged_on')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('amount_thb')->nullable();
            $table->boolean('entered_after_close')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'due_date']);
            $table->index('charged_on');
            $table->index(['credit_card_id', 'charged_on']);
        });

        Schema::create('subscription_charge_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_charge_id')->constrained()->restrictOnDelete();
            $table->string('action', 12);
            $table->json('diff')->nullable();
            $table->string('reason', 500)->nullable();
            $table->boolean('in_closed_period')->default(false);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('accounting_period_closures', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->index();
            $table->string('action', 10);
            $table->string('reason', 500)->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_closures');
        Schema::dropIfExists('subscription_charge_revisions');
        Schema::dropIfExists('subscription_charges');
    }
};
