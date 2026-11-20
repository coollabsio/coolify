<?php

use App\Http\Middleware\PreventHtmlTransform;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['fqdn' => null]));
    User::factory()->create();
});

it('tells proxies not to rewrite HTML pages and keeps them uncached', function () {
    $cacheControl = $this->get('/login')->assertOk()->headers->get('Cache-Control');

    expect(explode(', ', $cacheControl))->toContain('no-transform', 'no-cache', 'private');
});

it('does not change the Cache-Control of non-HTML responses', function () {
    $response = (new PreventHtmlTransform)->handle(Request::create('/'), fn () => response()->json(['ok' => true]));

    expect($response->headers->get('Cache-Control'))->not->toContain('no-transform');
});
