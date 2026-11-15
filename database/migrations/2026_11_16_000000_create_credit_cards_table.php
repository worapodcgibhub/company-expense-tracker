<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards the company pays services and subscriptions with. Purely additive:
 * creates a new table and does not touch any existing one.
 *
 * Security: only the last four digits are stored. There is deliberately no
 * column for the full card number, CVV, expiry date or PIN.
 *
 * Cards are never deleted (subscriptions in Phase 2.3 will reference them);
 * they are deactivated instead (`deactivated_at`, NULL = active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('issuer', 100);
            $table->string('network', 20);
            $table->string('kind', 20);
            $table->char('last_four', 4)->index();
            $table->string('holder_name', 100);
            $table->text('notes')->nullable();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
