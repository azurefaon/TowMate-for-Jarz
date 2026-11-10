<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Exact Philippine-peso amounts for the Owner Finance module.
 *
 * Values are decimal strings with two places, and all arithmetic is done with bcmath. Floats are refused
 * outright: 0.1 + 0.2 must be exactly 0.30, and a float can no longer guarantee that. Rounding is
 * half-up (away from zero) to the centavo, which matches the existing quotation and invoice rounding.
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    /** Working precision for intermediate multiplication before the single final rounding. */
    private const WORK_SCALE = 10;

    private function __construct(private readonly string $amount)
    {
    }

    /**
     * Accepts a decimal string ("1250", "1250.5", "1250.50"), an integer number of pesos, or a Money.
     * A string with more than two decimal places is refused: callers must round deliberately with
     * Money::roundHalfUp() so that precision is never lost silently.
     */
    public static function of(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        // Typed as mixed on purpose: a Money|string|int signature would silently coerce 0.1 to "0.1".
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Money accepts only decimal strings, integers or Money, never ' . get_debug_type($value) . '.');
        }

        $string = is_int($value) ? (string) $value : trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException("Invalid money amount [{$string}].");
        }

        $decimals = str_contains($string, '.') ? strlen(substr($string, strpos($string, '.') + 1)) : 0;
        if ($decimals > self::SCALE && rtrim(substr($string, strpos($string, '.') + 1 + self::SCALE), '0') !== '') {
            throw new InvalidArgumentException("Money amount [{$string}] has more than two decimal places.");
        }

        return new self(self::normalize(bcadd($string, '0', self::SCALE)));
    }

    /** Explicit, intentional rounding of an arbitrary-precision decimal string to the centavo. */
    public static function roundHalfUp(string $value): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
            throw new InvalidArgumentException("Invalid decimal [{$value}].");
        }

        return new self(self::round(trim($value), self::SCALE));
    }

    public static function fromCentavos(int $centavos): self
    {
        return new self(self::normalize(bcdiv((string) $centavos, '100', self::SCALE)));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    /** @param iterable<Money|string|int> $values */
    public static function sum(iterable $values): self
    {
        $total = self::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public function plus(mixed $other): self
    {
        return new self(self::normalize(bcadd($this->amount, self::of($other)->amount, self::SCALE)));
    }

    public function minus(mixed $other): self
    {
        return new self(self::normalize(bcsub($this->amount, self::of($other)->amount, self::SCALE)));
    }

    /** This amount × percent / 100, rounded half-up once at the end (for example, 12.00 for 12%). */
    public function percent(string $percent): self
    {
        $this->assertDecimal($percent);
        $raw = bcdiv(bcmul($this->amount, $percent, self::WORK_SCALE), '100', self::WORK_SCALE);

        return new self(self::round($raw, self::SCALE));
    }

    /** This amount × part / whole, rounded half-up once (for proportional shares). Whole must be positive. */
    public function shareOf(mixed $part, mixed $whole): self
    {
        $wholeAmount = self::of($whole);
        if (! $wholeAmount->isPositive()) {
            throw new InvalidArgumentException('The whole must be greater than zero.');
        }
        $raw = bcdiv(bcmul($this->amount, self::of($part)->amount, self::WORK_SCALE), $wholeAmount->amount, self::WORK_SCALE);

        return new self(self::round($raw, self::SCALE));
    }

    public function max(mixed $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : self::of($other);
    }

    public function min(mixed $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : self::of($other);
    }

    /**
     * Splits this amount across shares (key => percent) so that the parts always sum EXACTLY to this
     * amount. Each part is rounded half-up, and the rounding difference (at most a few centavos) goes
     * to $remainderKey. Percents must total exactly 100.
     *
     * @param  array<string, string>  $percents
     * @return array{parts: array<string, Money>, remainder: Money}
     */
    public function allocateByPercent(array $percents, string $remainderKey): array
    {
        if (! array_key_exists($remainderKey, $percents)) {
            throw new InvalidArgumentException("Remainder key [{$remainderKey}] is not one of the shares.");
        }

        $totalPercent = '0';
        foreach ($percents as $percent) {
            $this->assertDecimal($percent);
            $totalPercent = bcadd($totalPercent, $percent, 4);
        }
        if (bccomp($totalPercent, '100', 4) !== 0) {
            throw new InvalidArgumentException("Shares must total exactly 100 percent, got {$totalPercent}.");
        }

        $parts = [];
        foreach ($percents as $key => $percent) {
            $parts[$key] = $this->percent($percent);
        }

        $remainder = $this->minus(self::sum($parts));
        $parts[$remainderKey] = $parts[$remainderKey]->plus($remainder);

        return ['parts' => $parts, 'remainder' => $remainder];
    }

    public function compareTo(mixed $other): int
    {
        return bccomp($this->amount, self::of($other)->amount, self::SCALE);
    }

    public function equals(mixed $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->compareTo(self::zero()) === 0;
    }

    public function isPositive(): bool
    {
        return $this->compareTo(self::zero()) > 0;
    }

    public function isNegative(): bool
    {
        return $this->compareTo(self::zero()) < 0;
    }

    public function toCentavos(): int
    {
        return (int) bcmul($this->amount, '100', 0);
    }

    public function toString(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }

    /** Display form, e.g. "₱1,250.50". */
    public function format(): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->amount, '-'));
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($this->isNegative() ? '-' : '') . '₱' . $grouped . '.' . $fraction;
    }

    private function assertDecimal(string $value): void
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid percent [{$value}].");
        }
    }

    /** Half-up (away from zero) rounding. bcmath truncates, so add or subtract half a unit first. */
    private static function round(string $value, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        return self::normalize($rounded);
    }

    /** Collapse "-0.00" to "0.00". */
    private static function normalize(string $value): string
    {
        return bccomp($value, '0', self::SCALE) === 0 ? '0.00' : $value;
    }
}
