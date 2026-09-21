<?php

use App\Services\Infisical\InfisicalPath;
use App\Services\Infisical\InfisicalPathCollisionException;

it('returns root for the team scope', function () {
    expect(InfisicalPath::forTeam())->toBe('/');
});

it('builds a project path', function () {
    expect(InfisicalPath::forProject('Shop API'))->toBe('/shop-api/');
});

it('builds a resource path nested under its project', function () {
    expect(InfisicalPath::forResource('Shop API', 'API Server'))->toBe('/shop-api/api-server/');
});

it('slugifies environment names for the native infisical environment', function () {
    expect(InfisicalPath::environmentSlug('Production'))->toBe('production')
        ->and(InfisicalPath::environmentSlug('UAT / Staging'))->toBe('uat-staging');
});

it('strips characters that are illegal in a path segment', function () {
    expect(InfisicalPath::forProject('a/b'))->toBe('/a-b/')
        ->and(InfisicalPath::forProject('  Spaced  Out  '))->toBe('/spaced-out/');
});

it('accepts distinct names that slug distinctly', function () {
    expect(fn () => InfisicalPath::assertNoCollisions(['Shop API', 'Billing']))
        ->not->toThrow(InfisicalPathCollisionException::class);
});

it('throws when two distinct names slug identically', function () {
    expect(fn () => InfisicalPath::assertNoCollisions(['Shop API', 'shop  api']))
        ->toThrow(
            InfisicalPathCollisionException::class,
            'Infisical folder name collision: "Shop API" and "shop  api" both resolve to "shop-api".'
        );
});

it('does not consider a repeated identical name a collision', function () {
    expect(fn () => InfisicalPath::assertNoCollisions(['Shop API', 'Shop API']))
        ->not->toThrow(InfisicalPathCollisionException::class);
});

it('rejects a name that slugs to nothing', function () {
    expect(fn () => InfisicalPath::forProject('///'))
        ->toThrow(InfisicalPathCollisionException::class, 'resolves to an empty folder name');
});
