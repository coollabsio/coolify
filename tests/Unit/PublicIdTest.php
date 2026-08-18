<?php

it('generates lowercase URL-safe public ids without Cuid2', function () {
    $ids = collect(range(1, 100))->map(fn () => new_public_id());

    expect($ids)->each
        ->toBeString()
        ->toHaveLength(24)
        ->toMatch('/^[a-z0-9]+$/');

    expect($ids->unique())->toHaveCount(100);
});

it('honors custom public id lengths', function () {
    expect(new_public_id(32))
        ->toBeString()
        ->toHaveLength(32)
        ->toMatch('/^[a-z0-9]+$/');
});
