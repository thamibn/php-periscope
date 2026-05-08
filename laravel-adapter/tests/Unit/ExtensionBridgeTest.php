<?php

declare(strict_types=1);

use Periscope\Laravel\Bridge\ExtensionBridge;

it('reports unavailable when the C extension is not loaded', function (): void {
    $bridge = new ExtensionBridge(enabled: true);

    if (!function_exists('periscope_record_event')) {
        expect($bridge->isAvailable())->toBeFalse();
        expect($bridge->recordEvent('test', []))->toBeFalse();
        expect($bridge->checkpoint('test'))->toBeFalse();
    } else {
        expect($bridge->isAvailable())->toBeTrue();
    }
});

it('reports unavailable when disabled, even if the extension is loaded', function (): void {
    $bridge = new ExtensionBridge(enabled: false);
    expect($bridge->isAvailable())->toBeFalse();
});
