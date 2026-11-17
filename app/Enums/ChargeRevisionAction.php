<?php

namespace App\Enums;

/**
 * Stored values are at most 12 characters (subscription_charge_revisions.action).
 */
enum ChargeRevisionAction: string
{
    case Recorded = 'recorded';
    case Updated = 'updated';
    case Verified = 'verified';
    case Unverified = 'unverified';

    // Documents (Phase 2.5)
    case ReceiptAdded = 'receipt_add';
    case ReceiptUpdated = 'receipt_edit';
    case ReceiptReplaced = 'receipt_swap';
    case ReceiptRemoved = 'receipt_del';
    case ReceiptUnavailable = 'receipt_na';
    case ReceiptUnavailableCleared = 'receipt_na_x';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'บันทึกยอด',
            self::Updated => 'แก้ไข',
            self::Verified => 'ฝ่ายบัญชีตรวจสอบแล้ว',
            self::Unverified => 'ยกเลิกการตรวจสอบ',
            self::ReceiptAdded => 'แนบเอกสาร',
            self::ReceiptUpdated => 'แก้ไขข้อมูลเอกสาร',
            self::ReceiptReplaced => 'เปลี่ยนไฟล์เอกสาร',
            self::ReceiptRemoved => 'นำเอกสารออก',
            self::ReceiptUnavailable => 'ระบุว่าไม่มีใบเสร็จ',
            self::ReceiptUnavailableCleared => 'ยกเลิกสถานะไม่มีใบเสร็จ',
        };
    }

    public function isReceiptAction(): bool
    {
        return str_starts_with($this->value, 'receipt_');
    }
}
