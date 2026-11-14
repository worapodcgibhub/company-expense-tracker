<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Accountant = 'accountant';
    case Staff = 'staff';

    /**
     * Human readable (Thai) label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'เจ้าของบริษัท',
            self::Accountant => 'พนักงานบัญชี',
            self::Staff => 'พนักงานทั่วไป',
        };
    }
}
