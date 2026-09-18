<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Tools\ListServiceTemplates;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Tools\Request;

test('the catalog tool lists templates with slug, name and slogan', function () {
    $out = (string) app(ListServiceTemplates::class)->handle(new Request([]));

    expect($out)->toMatch('/^\d+ templates:/')
        ->and($out)->toContain('activepieces — Activepieces')
        ->and($out)->toContain('[automation]');
});

test('the catalog tool filters by search across name, slogan and tags', function () {
    $out = (string) app(ListServiceTemplates::class)->handle(new Request(['search' => 'activepieces']));

    expect($out)->toStartWith('1 templates:')
        ->and($out)->toContain('activepieces');
});

test('the catalog tool filters by category', function () {
    $out = (string) app(ListServiceTemplates::class)->handle(new Request(['category' => 'automation']));

    expect($out)->toContain('activepieces')
        ->and($out)->not->toContain('[database]');
});

test('the catalog tool honours the limit', function () {
    $out = (string) app(ListServiceTemplates::class)->handle(new Request(['limit' => 3]));

    expect($out)->toStartWith('Showing 3 of ')
        // header line + three template lines
        ->and(substr_count($out, "\n"))->toBe(3);
});

test('the catalog tool reports when nothing matches', function () {
    $out = (string) app(ListServiceTemplates::class)->handle(new Request(['search' => 'zzz-no-such-template-zzz']));

    expect($out)->toBe('No service templates matched.');
});

test('the assistant exposes the service template catalog tool', function () {
    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)->toContain(ListServiceTemplates::class);
});

test('the assistant marks its instructions and tool definitions for prompt caching', function () {
    $reflection = new ReflectionClass(CoolifyAssistant::class);

    expect($reflection->getAttributes(CacheInstructions::class))->not->toBeEmpty()
        ->and($reflection->getAttributes(CacheToolDefinitions::class))->not->toBeEmpty();
});
