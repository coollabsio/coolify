<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

test('base layout links the app icons and web manifest', function () {
    InstanceSettings::query()->forceCreate(['id' => 0]);
    Once::flush();
    User::factory()->create();

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'" />', false)
        ->assertSee('<link rel="manifest" href="'.asset('site.webmanifest').'" />', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Coolify" />', false);
});

test('icon files are opaque pngs with the required sizes', function (string $file, int $size) {
    $image = getimagesize(public_path($file));

    expect($image)->toMatchArray([0 => $size, 1 => $size, 'mime' => 'image/png']);
})->with([
    ['apple-touch-icon.png', 180],
    ['icon-192.png', 192],
    ['icon-512.png', 512],
    ['icon-maskable-512.png', 512],
]);

test('favicon ico contains 16, 32 and 48 pixel images', function () {
    $ico = file_get_contents(public_path('favicon.ico'));
    $header = unpack('vreserved/vtype/vcount', $ico);
    $sizes = collect(range(0, $header['count'] - 1))
        ->map(fn (int $index) => ord($ico[6 + 16 * $index]))
        ->all();

    expect($header['type'])->toBe(1)
        ->and($sizes)->toBe([16, 32, 48]);
});

test('web manifest lists the pwa icons', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Coolify')
        ->and($manifest['display'])->toBe('standalone')
        ->and(collect($manifest['icons'])->map(fn (array $icon) => [$icon['src'], $icon['sizes'], $icon['purpose'] ?? 'any'])->all())
        ->toBe([
            ['/icon-192.png', '192x192', 'any'],
            ['/icon-512.png', '512x512', 'any'],
            ['/icon-maskable-512.png', '512x512', 'maskable'],
        ]);

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
});
