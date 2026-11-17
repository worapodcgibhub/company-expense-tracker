<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.1a — payment methods and bank accounts. Additive only: no existing
 * table is rebuilt and no existing value is changed or removed.
 *
 * - `bank_accounts`: only the last four digits are stored. There is no
 *   column for the full account number, password, PIN or PromptPay ID.
 * - `payment_methods`: a card (-> credit_cards), a bank account (transfer /
 *   PromptPay / direct debit) or cash. One method per card (unique).
 * - Adds a NULLABLE `payment_method_id` (plain ADD COLUMN, no foreign key
 *   yet — SQLite cannot add one without rebuilding the table; the foreign
 *   key is added in Phase 3.1b when those tables are rebuilt anyway) to
 *   `subscriptions` and `subscription_charges`. `credit_card_id` stays as is.
 * - Backfills: every existing card gets a card payment method (auto), and
 *   each subscription / charge gets the method of its card. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('bank', 100);
            $table->string('account_name', 150);
            $table->char('last_four', 4)->index();
            $table->string('kind', 10);
            $table->text('notes')->nullable();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20)->index();
            $table->string('settlement', 10);
            $table->foreignId('credit_card_id')->nullable()->unique()->constrained('credit_cards')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->index()->constrained('bank_accounts')->restrictOnDelete();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->index();
        });

        Schema::table('subscription_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->index();
        });

        $this->backfill();
    }

    /**
     * Give every card a payment method and point subscriptions / charges at
     * it. Only fills what is still empty, so running it again is harmless.
     */
    public function backfill(): void
    {
        $now = now();

        DB::table('credit_cards')
            ->whereNotIn('id', DB::table('payment_methods')->whereNotNull('credit_card_id')->select('credit_card_id'))
            ->orderBy('id')
            ->get(['id', 'name', 'deactivated_at'])
            ->each(function (object $card) use ($now) {
                DB::table('payment_methods')->insert([
                    'name' => $card->name,
                    'type' => 'card',
                    'settlement' => 'auto',
                    'credit_card_id' => $card->id,
                    'bank_account_id' => null,
                    'deactivated_at' => $card->deactivated_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        foreach (['subscriptions', 'subscription_charges'] as $table) {
            DB::statement(
                "UPDATE {$table} SET payment_method_id = (
                    SELECT payment_methods.id FROM payment_methods
                    WHERE payment_methods.credit_card_id = {$table}.credit_card_id
                ) WHERE payment_method_id IS NULL"
            );
        }
    }

    public function down(): void
    {
        foreach (['subscription_charges', 'subscriptions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['payment_method_id']);
            });
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('payment_method_id');
            });
        }

        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('bank_accounts');
    }
};
