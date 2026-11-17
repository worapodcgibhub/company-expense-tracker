<?php

use App\Models\User;
use App\Support\ThaiFormat;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows the summary cards and sections in Thai', function () {
    $this->actingAs(User::factory()->create(['name' => 'สมชาย ใจดี']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('สวัสดี, สมชาย')
        ->assertSee(ThaiFormat::dateWithDay(now()))
        ->assertSeeInOrder([
            'ค่าใช้จ่ายเดือนนี้',
            'บันทึกจ่ายจริงแล้ว',
            'ใกล้ถึงกำหนดชำระ',
            'ขาดใบเสร็จ',
        ])
        ->assertSee('กำหนดชำระที่กำลังจะถึง')
        ->assertSee('ต้องดำเนินการ')
        ->assertSee('ค่าใช้จ่ายรายเดือน')
        ->assertSee('แยกตามหมวดหมู่')
        ->assertSee('รายการล่าสุด');
});

test('dashboard shows empty states and zero totals when there is no data', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('฿0.00')
        ->assertSee('ยังไม่มีกำหนดชำระที่กำลังจะถึง')
        ->assertSee('ไม่มีรายการค้าง')
        ->assertSee('ยังไม่มีข้อมูลค่าใช้จ่าย')
        ->assertSee('ยังไม่มีข้อมูลหมวดหมู่')
        ->assertSee('ยังไม่มีการบันทึกค่าใช้จ่าย')
        ->assertSee('data-empty-state', false);
});

test('dashboard shows the last six months on the chart', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    foreach (ThaiFormat::recentMonths(6) as $month) {
        $response->assertSee(ThaiFormat::monthShort($month));
    }
});

test('dashboard shows the user role', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->get(route('dashboard'))
        ->assertSee('เจ้าของบริษัท');
});

test('dashboard reminds users without 2FA to enable it', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('data-test="two-factor-reminder"', false)
        ->assertSee(route('security.edit'));
});

test('dashboard hides the 2FA reminder once 2FA is enabled', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertDontSee('data-test="two-factor-reminder"', false);
});
