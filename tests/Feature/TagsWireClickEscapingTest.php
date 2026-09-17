<?php

use Illuminate\Support\Facades\Blade;

/**
 * `wire:click` is evaluated as JavaScript by Alpine, so a value interpolated
 * into a quoted string inside it can close that string. Tag names are supplied
 * by team members, which makes them untrusted here.
 */
$hostileName = "backend',alert(document.domain),'";

it('does not let a tag name break out of the wire:click expression', function () use ($hostileName) {
    $html = Blade::render(
        '<button wire:click="addTag(@js($id), @js($name))"></button>',
        ['id' => 7, 'name' => $hostileName]
    );

    expect(preg_match('/wire:click="([^"]*)"/', $html, $matches))->toBe(1);

    $expression = html_entity_decode($matches[1], ENT_QUOTES);

    // The apostrophes of the tag name survive as escape sequences, so they
    // cannot terminate the string literal Alpine evaluates.
    expect($expression)->toContain('\u0027');
    expect($expression)->not->toContain("',alert(");
});

it('keeps the tags partial free of interpolated JavaScript string literals', function () {
    $blade = file_get_contents(
        resource_path('views/livewire/project/shared/tags.blade.php')
    );

    expect($blade)->not->toMatch('/wire:[a-z.]+="[^"]*\'\s*\{\{/');
});
