<?php

use App\Support\Money;

it('adds decimals exactly where floats drift', function () {
    expect(Money::of('0.10')->plus('0.20')->toString())->toBe('0.30')
        ->and(Money::sum(array_fill(0, 10, '0.10'))->toString())->toBe('1.00');
});

it('refuses floats everywhere', function () {
    expect(fn () => Money::of(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1.00')->plus(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1.00')->minus(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(null))->toThrow(InvalidArgumentException::class);
});

it('refuses silent precision loss but accepts trailing zeros', function () {
    expect(fn () => Money::of('10.005'))->toThrow(InvalidArgumentException::class)
        ->and(Money::of('10.500')->toString())->toBe('10.50')
        ->and(Money::of(1250)->toString())->toBe('1250.00')
        ->and(fn () => Money::of('1,250.00'))->toThrow(InvalidArgumentException::class);
});

it('rounds percentages half-up to the centavo', function () {
    expect(Money::of('0.05')->percent('10')->toString())->toBe('0.01')   // 0.005 -> 0.01
        ->and(Money::of('0.04')->percent('12')->toString())->toBe('0.00')  // 0.0048 -> 0.00
        ->and(Money::of('1999.99')->percent('12')->toString())->toBe('240.00') // 239.9988
        ->and(Money::roundHalfUp('-2.345')->toString())->toBe('-2.35');
});

it('allocates commission shares that always sum exactly to the base', function (string $base) {
    $rates = ['company' => '60', 'driver' => '12', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '8'];
    $result = Money::of($base)->allocateByPercent($rates, 'company');

    expect(Money::sum($result['parts'])->toString())->toBe(Money::of($base)->toString());
})->with(['1000.00', '1000.01', '0.03', '1234.57', '99999.99', '0.00']);

it('puts the rounding remainder on the designated share', function () {
    // 0.05 split 50/50: each 0.025 rounds up to 0.03, total 0.06, so the remainder of -0.01 goes to company.
    $result = Money::of('0.05')->allocateByPercent(['company' => '50', 'driver' => '50'], 'company');

    expect($result['parts']['driver']->toString())->toBe('0.03')
        ->and($result['parts']['company']->toString())->toBe('0.02')
        ->and($result['remainder']->toString())->toBe('-0.01');
});

it('refuses shares that do not total 100 percent', function () {
    expect(fn () => Money::of('100')->allocateByPercent(['company' => '60', 'driver' => '30'], 'company'))
        ->toThrow(InvalidArgumentException::class);
});

it('converts centavos and formats pesos', function () {
    expect(Money::fromCentavos(125050)->toString())->toBe('1250.50')
        ->and(Money::of('1250.50')->toCentavos())->toBe(125050)
        ->and(Money::of('1234567.80')->format())->toBe('₱1,234,567.80')
        ->and(Money::of('-5.00')->format())->toBe('-₱5.00')
        ->and(Money::of('-0.00')->toString())->toBe('0.00');
});
