<?php

declare(strict_types=1);

use Periscope\Laravel\Support\CallSiteResolver;

it('returns the topmost user-code frame', function (): void {
    $resolver = new CallSiteResolver(snippetLines: 0);
    $cs = $resolver->resolve();

    expect($cs)->not->toBeNull()
        ->and($cs['file'])->toBe(__FILE__)
        ->and($cs['line'])->toBeInt()->toBeGreaterThan(0);
});

it('skips vendor paths', function (): void {
    $resolver = new CallSiteResolver(
        vendorSkip: [__FILE__],
        snippetLines: 0,
    );
    // The current file is now in the skip list — resolver should walk past it
    // and find some other frame (or null if everything's skipped).
    $cs = $resolver->resolve();
    if ($cs !== null) {
        expect($cs['file'])->not->toBe(__FILE__);
    }
});

it('captures source snippet around the call line', function (): void {
    $resolver = new CallSiteResolver(snippetLines: 1);
    $cs = $resolver->resolve();

    expect($cs)->not->toBeNull()
        ->and($cs['snippet'])->toBeArray()->not->toBeEmpty();

    $lineNumbers = array_column($cs['snippet'], 'number');
    expect($lineNumbers)->toContain($cs['line']);
});
