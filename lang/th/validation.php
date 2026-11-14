<?php

/*
 * Thai validation messages for the rules used so far.
 * Any rule not listed here falls back to Laravel's English message.
 */
return [
    'confirmed' => ':attribute และการยืนยันไม่ตรงกัน',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'email' => ':attribute ต้องเป็นอีเมลที่ถูกต้อง',
    'max' => [
        'string' => ':attribute ต้องยาวไม่เกิน :max ตัวอักษร',
    ],
    'min' => [
        'string' => ':attribute ต้องยาวอย่างน้อย :min ตัวอักษร',
    ],
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อย 1 ตัว',
        'mixed' => ':attribute ต้องมีทั้งตัวพิมพ์ใหญ่และตัวพิมพ์เล็ก',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อย 1 ตัว',
        'symbols' => ':attribute ต้องมีสัญลักษณ์อย่างน้อย 1 ตัว เช่น ! @ # $',
        'uncompromised' => ':attribute นี้เคยรั่วไหลในเหตุการณ์ข้อมูลรั่ว กรุณาใช้รหัสผ่านอื่น',
    ],
    'required' => 'กรุณากรอก:attribute',
    'string' => ':attribute ต้องเป็นข้อความ',
    'unique' => ':attribute นี้ถูกใช้งานแล้ว',

    'attributes' => [
        'name' => 'ชื่อ',
        'email' => 'อีเมล',
        'password' => 'รหัสผ่าน',
        'current_password' => 'รหัสผ่านปัจจุบัน',
        'password_confirmation' => 'การยืนยันรหัสผ่าน',
        'code' => 'รหัสยืนยันตัวตน',
        'recovery_code' => 'รหัสกู้คืน',
    ],
];
