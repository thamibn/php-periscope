<?php

declare(strict_types=1);

/**
 * Phase 2 fixture — exercises the Observer API.
 *
 * Runs a handful of userland calls covering primitives, arrays, objects,
 * recursion, closures, and a typed return value. The expectation is that
 * each call appears in stderr as a [periscope] enter / exit pair.
 */

final class Greeter
{
    public function __construct(
        private readonly string $prefix = 'Hi',
    ) {}

    public function greet(string $name, int $exclamations = 1): string
    {
        return $this->prefix . ', ' . $name . str_repeat('!', $exclamations);
    }
}

function fib(int $n): int
{
    return $n < 2 ? $n : fib($n - 1) + fib($n - 2);
}

$greeter = new Greeter('Hello');
echo $greeter->greet('Thami', 2), PHP_EOL;

$double = fn(int $x): int => $x * 2;
echo $double(21), PHP_EOL;

echo fib(5), PHP_EOL;

echo array_sum([1, 2, 3, 4]), PHP_EOL;
