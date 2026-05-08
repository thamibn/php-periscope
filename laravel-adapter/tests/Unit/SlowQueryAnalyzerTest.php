<?php

declare(strict_types=1);

use Periscope\Laravel\Detection\SlowQueryAnalyzer;

beforeEach(function (): void {
    $this->bridge   = periscopeRecordingBridge();
    $this->analyzer = new SlowQueryAnalyzer($this->bridge);
});

it('flags SELECT * as over-fetching', function (): void {
    $issues = $this->analyzer->detect('SELECT * FROM listings WHERE id = ?');

    $codes = array_column($issues, 'code');
    expect($codes)->toContain('select_star');
});

it('flags leading-wildcard LIKE as index-killer', function (): void {
    $issues = $this->analyzer->detect("SELECT id FROM listings WHERE title LIKE '%villa%'");

    $codes = array_column($issues, 'code');
    expect($codes)->toContain('leading_wildcard_like');
});

it('flags function-on-column as index-killer', function (): void {
    $issues = $this->analyzer->detect('SELECT id FROM listings WHERE LOWER(slug) = ?');

    $codes = array_column($issues, 'code');
    expect($codes)->toContain('function_on_column');
});

it('flags ORDER BY without LIMIT', function (): void {
    $issues = $this->analyzer->detect('SELECT id FROM listings WHERE agency_id = ? ORDER BY created_at DESC');

    $codes = array_column($issues, 'code');
    expect($codes)->toContain('order_by_no_limit');
});

it('flags unbounded SELECT without WHERE or LIMIT', function (): void {
    $issues = $this->analyzer->detect('SELECT id, title FROM listings');

    $codes = array_column($issues, 'code');
    expect($codes)->toContain('unbounded_select');
});

it('emits a slow_query_warning event when issues are detected', function (): void {
    $this->analyzer->analyse('mysql', "SELECT * FROM listings WHERE title LIKE '%villa%'", 220.0, null);

    expect($this->bridge->events)->toHaveCount(1)
        ->and($this->bridge->events[0]['type'])->toBe('slow_query_warning')
        ->and($this->bridge->events[0]['payload']['issues'])->not->toBeEmpty();
});

it('emits nothing when no issues are detected', function (): void {
    $this->analyzer->analyse('mysql', 'SELECT id FROM listings WHERE id = ? LIMIT 1', 220.0, null);

    expect($this->bridge->events)->toBeEmpty();
});
