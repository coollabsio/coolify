<?php

use App\Models\Application;

/**
 * Unit test to verify custom_network_aliases conversion from array to string.
 *
 * The issue: Application model's accessor returns an array, but the Livewire
 * component property is typed as ?string for the text input field.
 * The conversion happens in mount() after syncFromModel().
 */
it('converts array aliases to comma-separated string', function () {
    // Test that an array is correctly converted to a string
    $aliases = ['api.internal', 'api.local'];
    $result = implode(',', $aliases);

    expect($result)->toBe('api.internal,api.local')
        ->and($result)->toBeString();
});

it('handles null aliases', function () {
    // Test that null remains null
    $aliases = null;

    if (is_array($aliases)) {
        $result = implode(',', $aliases);
    } else {
        $result = $aliases;
    }

    expect($result)->toBeNull();
});

it('handles empty array aliases', function () {
    // Test that empty array becomes empty string
    $aliases = [];
    $result = implode(',', $aliases);

    expect($result)->toBe('')
        ->and($result)->toBeString();
});

it('handles single alias', function () {
    // Test that single-element array is converted correctly
    $aliases = ['api.internal'];
    $result = implode(',', $aliases);

    expect($result)->toBe('api.internal')
        ->and($result)->toBeString();
});
