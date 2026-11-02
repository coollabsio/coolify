<?php

use App\Support\Metrics\PressureLevel;

it('classifies cpu/memory pressure at 70/90 cutoffs', function () {
    expect(PressureLevel::for(10, 'cpu'))->toBe('ok');
    expect(PressureLevel::for(70, 'cpu'))->toBe('warning');
    expect(PressureLevel::for(89.9, 'memory'))->toBe('warning');
    expect(PressureLevel::for(90, 'memory'))->toBe('error');
});

it('classifies disk pressure at 80/90 cutoffs', function () {
    expect(PressureLevel::for(75, 'disk'))->toBe('ok');
    expect(PressureLevel::for(80, 'disk'))->toBe('warning');
    expect(PressureLevel::for(95, 'disk'))->toBe('error');
});

it('treats a null percent as ok and maps levels to classes', function () {
    expect(PressureLevel::for(null, 'cpu'))->toBe('ok');
    expect(PressureLevel::textClass('error'))->toContain('text-red-500');
    expect(PressureLevel::barClass('warning'))->toContain('bg-orange-500');
});
