<?php

use App\Rules\NoFullCardNumber;

test('full card numbers are detected', function (string $text) {
    expect(NoFullCardNumber::containsCardNumber($text))->toBeTrue();
})->with([
    'visa' => '4111111111111111',
    'visa spaced' => '4111 1111 1111 1111',
    'mastercard dashed' => '5555-5555-5555-4444',
    'amex (15 digits)' => '378282246310005',
    'inside thai text' => 'บัตรเลข4111111111111111ของฝ่ายบัญชี',
    'longer than 19 digits' => '12345678901234567890',
]);

test('ordinary text and numbers are not flagged', function (string $text) {
    expect(NoFullCardNumber::containsCardNumber($text))->toBeFalse();
})->with([
    'empty' => '',
    'last four' => '4242',
    'phone number' => '081-234-5678',
    'amount and date' => 'วงเงิน 50,000 บาท ตัดบัตรวันที่ 25/12/2026',
    'non-luhn 16 digits' => '4111111111111112',
    'two separate short numbers' => '1234 5678',
]);
