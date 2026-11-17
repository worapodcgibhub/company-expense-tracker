<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.1b — lets subscriptions and charges be paid without a card.
 *
 * - `credit_card_id` becomes NULLABLE on `subscriptions` and
 *   `subscription_charges` (its foreign key and index are kept).
 * - `payment_method_id` (added in Phase 3.1a) gets its foreign key to
 *   `payment_methods` (restrict on delete). It stays nullable.
 *
 * SQLite cannot do either change in place, so Laravel rebuilds each table
 * (create temp table, copy every row with its id, drop, rename, re-create
 * indexes). To make that safe this migration:
 * - switches foreign key checking off BEFORE the transaction (SQLite
 *   ignores that switch inside one) so dropping a parent table does not
 *   trip over the receipts / revisions / status-change rows that point at it;
 * - does everything in ONE transaction, so any failure leaves the database
 *   exactly as it was;
 * - runs PRAGMA foreign_key_check before committing and aborts on any
 *   violation;
 * - first links rows that still lack `payment_method_id` to the EXISTING
 *   method of their card (never creates a method) and aborts if any row
 *   cannot be linked.
 *
 * Tables that point at these two (charge_receipts, subscription_charge_
 * revisions, subscription_status_changes) and accounting_period_closures
 * are not touched. down() refuses to run if any row has no card, instead of
 * deleting or changing data.
 */
return new class extends Migration
{
    private const TABLES = ['subscriptions', 'subscription_charges'];

    public function up(): void
    {
        $this->withForeignKeysOff(function () {
            DB::transaction(function () {
                $this->linkRowsToExistingMethods();

                foreach (self::TABLES as $name) {
                    Schema::table($name, function (Blueprint $table) {
                        $table->unsignedBigInteger('credit_card_id')->nullable()->change();
                        $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
                    });
                }

                $this->assertForeignKeysIntact();
            });
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $without = DB::table($name)->whereNull('credit_card_id')->count();

            if ($without > 0) {
                throw new RuntimeException("ย้อนกลับไม่ได้: ตาราง {$name} มี {$without} แถวที่ไม่มีบัตร (credit_card_id ว่าง) ระบบไม่ลบหรือแก้ข้อมูลให้ กรุณาตรวจสอบก่อน");
            }
        }

        $this->withForeignKeysOff(function () {
            DB::transaction(function () {
                foreach (self::TABLES as $name) {
                    Schema::table($name, function (Blueprint $table) {
                        $table->dropForeign(['payment_method_id']);
                        $table->unsignedBigInteger('credit_card_id')->nullable(false)->change();
                    });
                }

                $this->assertForeignKeysIntact();
            });
        });
    }

    /**
     * Only links; never creates a payment method. Rows with a card whose
     * method does not exist stop the migration.
     */
    private function linkRowsToExistingMethods(): void
    {
        foreach (self::TABLES as $name) {
            DB::statement(
                "UPDATE {$name} SET payment_method_id = (
                    SELECT payment_methods.id FROM payment_methods
                    WHERE payment_methods.credit_card_id = {$name}.credit_card_id
                ) WHERE payment_method_id IS NULL AND credit_card_id IS NOT NULL"
            );

            $unlinked = DB::table($name)->whereNull('payment_method_id')->count();

            if ($unlinked > 0) {
                throw new RuntimeException("หยุด Migration: ตาราง {$name} มี {$unlinked} แถวที่ยังไม่มีช่องทางชำระเงิน และหาช่องทางของบัตรไม่พบ (ระบบไม่สร้างหรือเดาให้) ไม่มีการเปลี่ยนแปลงใด ๆ");
            }
        }
    }

    private function assertForeignKeysIntact(): void
    {
        $violations = DB::select('PRAGMA foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('พบความสัมพันธ์ข้อมูลที่ผิดปกติ ('.count($violations).' รายการ) หลังสร้างตารางใหม่ ยกเลิกการเปลี่ยนแปลงทั้งหมดแล้ว');
        }
    }

    private function withForeignKeysOff(Closure $work): void
    {
        $wasOn = (bool) DB::scalar('PRAGMA foreign_keys');

        if ($wasOn) {
            Schema::disableForeignKeyConstraints();
        }

        try {
            $work();
        } finally {
            if ($wasOn) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
