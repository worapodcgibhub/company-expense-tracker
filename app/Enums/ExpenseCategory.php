<?php

namespace App\Enums;

/**
 * Fixed default categories of one-off expenses. The existing
 * SubscriptionCategory is about recurring services and does not fit
 * equipment, repairs or travel, and there is no categories table, so this
 * follows the same pattern (a string column backed by an enum).
 */
enum ExpenseCategory: string
{
    case Equipment = 'equipment';
    case Repair = 'repair';
    case Travel = 'travel';
    case Office = 'office';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Equipment => 'อุปกรณ์',
            self::Repair => 'ซ่อมบำรุง',
            self::Travel => 'เดินทาง',
            self::Office => 'สำนักงาน',
            self::Other => 'อื่น ๆ',
        };
    }
}
