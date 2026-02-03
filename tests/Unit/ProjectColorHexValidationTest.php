<?php

test('hex color regex accepts valid 6-character hex codes', function () {
    $validColors = [
        '#000000', // black
        '#FFFFFF', // white
        '#FF0000', // red
        '#00FF00', // green
        '#0000FF', // blue
        '#FF5733', // orange
        '#abcdef', // lowercase
        '#ABCDEF', // uppercase
        '#123456', // numbers only
        '#a1B2c3', // mixed case
    ];

    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    foreach ($validColors as $color) {
        expect(preg_match($pattern, $color))->toBe(1);
    }
});

test('hex color regex rejects invalid hex codes', function () {
    $invalidColors = [
        '#FFF',          // too short (3 chars)
        '#FFFFFFF',      // too long (7 chars)
        'FF5733',        // missing hash
        '#GG5733',       // invalid character G
        '#FF57ZZ',       // invalid characters Z
        '#12 34 56',     // spaces
        '#12-34-56',     // dashes
        'rgb(255,0,0)',  // not hex format
        '#',             // just hash
        '',              // empty string
        '#FF57',         // too short (4 chars)
        '#FF5',          // too short (3 chars)
    ];

    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    foreach ($invalidColors as $color) {
        expect(preg_match($pattern, $color))->toBe(0);
    }
});

test('hex color regex pattern is correctly formatted', function () {
    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    // Verify the pattern itself is valid
    expect(@preg_match($pattern, ''))->not->toBeFalse();
});

test('hex color regex accepts common colors', function () {
    $commonColors = [
        '#FF0000', // Red
        '#00FF00', // Green (Lime)
        '#0000FF', // Blue
        '#FFFF00', // Yellow
        '#FF00FF', // Magenta
        '#00FFFF', // Cyan
        '#000000', // Black
        '#FFFFFF', // White
        '#808080', // Gray
        '#FFA500', // Orange
        '#800080', // Purple
        '#008000', // Dark Green
        '#FFC0CB', // Pink
        '#A52A2A', // Brown
    ];

    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    foreach ($commonColors as $color) {
        expect(preg_match($pattern, $color))->toBe(1);
    }
});

test('hex color regex rejects 3-char shorthand hex codes', function () {
    // HTML supports 3-char hex (#FFF), but we require 6-char format
    $shorthandColors = [
        '#FFF', // white shorthand
        '#000', // black shorthand
        '#F00', // red shorthand
        '#0F0', // green shorthand
        '#00F', // blue shorthand
    ];

    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    foreach ($shorthandColors as $color) {
        expect(preg_match($pattern, $color))->toBe(0);
    }
});

test('hex color regex rejects color names', function () {
    $colorNames = [
        'red',
        'blue',
        'green',
        'black',
        'white',
        'transparent',
    ];

    $pattern = '/^#[0-9A-Fa-f]{6}$/';

    foreach ($colorNames as $color) {
        expect(preg_match($pattern, $color))->toBe(0);
    }
});
