<?php

use App\Models\Service;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

/**
 * Stores a service templates bundle in the shared cache, as the CDN pull does.
 *
 * @param  list<string>  $keys
 */
function storeServiceTemplateAliasBundle(array $keys): void
{
    $templates = collect($keys)->mapWithKeys(fn (string $key) => [$key => ['compose' => '', 'documentation' => "https://example.com/{$key}"]])->all();
    Cache::forever(service_templates_cache_key(), [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode($templates, JSON_THROW_ON_ERROR),
    ]);
}

it('resolves the old key of a renamed service template to the new key', function () {
    storeServiceTemplateAliasBundle(['deno-kv', 'wordpress']);

    expect(resolve_service_template_key('denoKV'))->toBe('deno-kv')
        ->and(data_get(get_service_templates(), resolve_service_template_key('denoKV').'.documentation'))->toBe('https://example.com/deno-kv');
});

it('keeps a key that the templates contain', function (array $keys, string $key) {
    storeServiceTemplateAliasBundle($keys);

    expect(resolve_service_template_key($key))->toBe($key);
})->with([
    'current key' => [['deno-kv'], 'deno-kv'],
    'old key in an old templates bundle' => [['denoKV'], 'denoKV'],
    'unknown key' => [['deno-kv'], 'unknown-template'],
]);

it('finds the template of an existing service that was created with the old key', function () {
    storeServiceTemplateAliasBundle(['deno-kv']);
    $templates = json_decode(Cache::get(service_templates_cache_key())['json'], true);
    $templates['deno-kv']['port'] = '4512';
    Cache::forever(service_templates_cache_key(), [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode($templates, JSON_THROW_ON_ERROR),
    ]);

    $service = (new Service)->forceFill(['name' => 'denoKV-abc123', 'service_type' => 'denoKV']);

    expect($service->documentation())->toBe('https://example.com/deno-kv')
        ->and($service->getRequiredPort())->toBe(4512);
});
