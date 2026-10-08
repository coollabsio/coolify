<?php

describe('countryFlagEmoji', function () {
    it('returns the correct flag for a valid uppercase code', function () {
        expect(countryFlagEmoji('US'))->toBe('🇺🇸');
    });

    it('is case-insensitive', function () {
        expect(countryFlagEmoji('us'))->toBe('🇺🇸');
    });

    it('returns the globe fallback for invalid input', function (?string $input) {
        expect(countryFlagEmoji($input))->toBe('🌐');
    })->with([
        'null' => [null],
        'empty' => [''],
        'three letters' => ['USA'],
        'non-letters' => ['1!'],
        'single letter' => ['U'],
    ]);
});

describe('countryName', function () {
    it('returns the English region name for a valid uppercase code', function () {
        expect(countryName('US'))->toBe('United States');
    });

    it('is case-insensitive', function () {
        expect(countryName('us'))->toBe('United States');
    });

    it('returns Unknown for invalid input', function (?string $input) {
        expect(countryName($input))->toBe('Unknown');
    })->with([
        'null' => [null],
        'empty' => [''],
        'unresolvable ZZ' => ['ZZ'],
        'unresolvable XX' => ['XX'],
        'three letters' => ['USA'],
        'non-letters' => ['1!'],
    ]);
});
