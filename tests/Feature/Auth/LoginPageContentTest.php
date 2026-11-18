<?php

/*
 * Mini MVP round 3: the brand panel of the login page speaks about every
 * kind of company expense, not only credit cards.
 */

test('the login page shows the new headline, description and three highlights', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('จัดการค่าใช้จ่ายบริษัท')
        ->assertSee('ครบ จบ ในที่เดียว')
        ->assertSee('ติดตามกำหนดชำระ บันทึกค่าใช้จ่าย และตรวจสอบเอกสารได้อย่างเป็นระบบ เพื่อให้ทุกยอดจ่ายไม่ตกหล่น')
        ->assertSee('รู้ล่วงหน้า')
        ->assertSee('รายการใกล้ตัดบัตรหรือถึงกำหนดโอน')
        ->assertSee('บันทึกครบ')
        ->assertSee('ทั้งค่าใช้จ่ายประจำและค่าใช้จ่ายทั่วไป')
        ->assertSee('ตรวจสอบง่าย')
        ->assertSee('ยอดจ่ายจริง รายงานรายเดือน และใบเสร็จ');
});

test('the login page no longer suggests that only credit cards are supported', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('รอบตัดบัตร')
        ->assertDontSee('ไม่พลาดทุกรอบตัดบัตร')
        ->assertDontSee('ติดตามทุกค่าใช้จ่าย')
        ->assertDontSee('ล่วงหน้า 7, 3 และ 1 วัน');
});

test('the other sign-in pages share the same brand panel', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('ครบ จบ ในที่เดียว')
        ->assertDontSee('รอบตัดบัตร');
});
