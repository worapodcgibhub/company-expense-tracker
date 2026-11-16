<?php

namespace App\Enums;

enum ChargeRevisionAction: string
{
    case Recorded = 'recorded';
    case Updated = 'updated';
    case Verified = 'verified';
    case Unverified = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'บันทึกยอด',
            self::Updated => 'แก้ไข',
            self::Verified => 'ฝ่ายบัญชีตรวจสอบแล้ว',
            self::Unverified => 'ยกเลิกการตรวจสอบ',
        };
    }
}
