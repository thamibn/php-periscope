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

it('statementSnippet captures a multi-line Eloquent chain as one block', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'periscope-stmt-');
    file_put_contents(
        $tmp,
        "<?php\n\$x = 1;\n\$users = User::query()\n    ->where('status', 'active')\n    ->orderBy('id')\n    ->get();\n\$y = 2;\n"
    );

    $resolver   = new CallSiteResolver(snippetLines: 0);
    $reflection = new ReflectionClass($resolver);
    $method     = $reflection->getMethod('statementSnippet');
    $method->setAccessible(true);

    // Inside ->where(...) (line 4) — should expand up to ::query (line 3) and
    // down to ->get(); (line 6), giving the whole chain back.
    $snippet = $method->invoke($resolver, $tmp, 4);
    $lines   = array_column($snippet, 'number');

    expect($lines)->toBe([3, 4, 5, 6])
        ->and($snippet[0]['source'])->toContain('User::query()')
        ->and($snippet[3]['source'])->toContain('->get();');

    unlink($tmp);
});
