<?php

use App\Support\MoneyInput;

test('typed amounts become minor units', function (string $input, int $expected) {
    expect(MoneyInput::toMinor($input))->toBe($expected);
})->with([
    ['590', 59000],
    ['54.99', 5499],
    ['0.1', 10],
    ['0.10', 10],
    ['1,234.56', 123456],
    ['1,234,567', 123456700],
    ['  590.5  ', 59050],
    ['0', 0],
    ['007', 700],
    ['99,999,999.99', 9_999_999_999],
]);

test('invalid amounts are rejected', function (string $input) {
    expect(MoneyInput::toMinor($input))->toBeNull();
})->with(['', 'abc', '1.234', '-5', '+5', '1e3', '12,34', '1,2345', '.5', '5.', '1 000', '฿590', '100000000', '590,00']);

test('minor units go back to a form value', function () {
    expect(MoneyInput::fromMinor(59000))->toBe('590.00')
        ->and(MoneyInput::fromMinor(5499))->toBe('54.99')
        ->and(MoneyInput::fromMinor(5))->toBe('0.05')
        ->and(MoneyInput::toMinor(MoneyInput::fromMinor(123456)))->toBe(123456);
});
