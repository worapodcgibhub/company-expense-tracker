<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring services the company pays by card. Purely additive: creates two
 * new tables and does not touch any existing one.
 *
 * - `estimated_amount` is the EXPECTED amount only, in minor units of
 *   `currency`. Actual charged amounts get their own table in Phase 2.4.
 * - `billing_day` is the contract day (1–31) every next charge date is
 *   computed from (see App\Support\BillingSchedule).
 * - Subscriptions are never deleted. Pausing and cancelling only change
 *   `status`; every change is kept in `subscription_status_changes`.
 *   Foreign keys restrict deletes so that history can never disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('vendor', 150);
            $table->string('category', 30)->index();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->restrictOnDelete();
            $table->string('billing_cycle', 10);
            $table->unsignedTinyInteger('billing_day');
            $table->date('next_charge_date');
            $table->char('currency', 3);
            $table->unsignedBigInteger('estimated_amount');
            $table->unsignedTinyInteger('remind_days_before')->default(3);
            $table->string('status', 10)->default('active');
            $table->timestamp('status_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_charge_date']);
        });

        Schema::create('subscription_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 10)->nullable();
            $table->string('to_status', 10);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_status_changes');
        Schema::dropIfExists('subscriptions');
    }
};
