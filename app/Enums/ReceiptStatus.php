<?php

namespace App\Enums;

/**
 * Document status of one actual charge (derived, not stored):
 *
 * - Waiting: charged, no document yet, nobody said there is none.
 * - Attached: at least one document that has not been removed.
 * - Unavailable: no document, and someone recorded why there is none.
 * - NotNeeded: the cycle was skipped (nothing was paid).
 */
enum ReceiptStatus: string
{
    case Waiting = 'waiting';
    case Attached = 'attached';
    case Unavailable = 'unavailable';
    case NotNeeded = 'not_needed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'รอใบเสร็จ',
            self::Attached => 'มีเอกสารแล้ว',
            self::Unavailable => 'ไม่มีใบเสร็จ',
            self::NotNeeded => 'ไม่ต้องใช้เอกสาร',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Waiting => 'red',
            self::Attached => 'emerald',
            self::Unavailable => 'slate',
            self::NotNeeded => 'slate',
        };
    }
}
