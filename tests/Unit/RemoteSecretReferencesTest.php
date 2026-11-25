<?php

use App\Support\RemoteSecretReferences;

test('detects references only for the vault namespace', function () {
    expect(RemoteSecretReferences::containsReference('{{vault.DB_PASSWORD}}'))->toBeTrue()
        ->and(RemoteSecretReferences::containsReference('{{doppler.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{infisical.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{ vault.KEY }}'))->toBeTrue()
        ->and(RemoteSecretReferences::containsReference('pre-{{vault.KEY}}-post'))->toBeTrue();
});

test('ignores the secret namespace, shared variables, and plain values', function () {
    expect(RemoteSecretReferences::containsReference('{{secret.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{team.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{project.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('plain'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('$OTHER_VAR'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference(null))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference(''))->toBeFalse();
});

test('extracts unique referenced keys in order', function () {
    $value = 'a={{vault.A}} ignored={{doppler.B}} again={{vault.A}}';

    expect(RemoteSecretReferences::referencedKeys($value))->toBe(['A']);
});

test('handles padded reference syntax consistently', function () {
    expect(RemoteSecretReferences::referencedKeys('{{ vault.A }}'))->toBe(['A'])
        ->and(RemoteSecretReferences::substitute('{{ vault.A }} {{ vault.MISSING }}', ['A' => 'value-a']))
        ->toBe('value-a {{ vault.MISSING }}')
        ->and(RemoteSecretReferences::missingKeys('{{ vault.A }} {{ vault.MISSING }}', ['A' => 'value-a']))
        ->toBe(['MISSING']);
});

test('substitutes references and leaves unknown keys untouched', function () {
    $secrets = ['A' => 'value-a'];

    expect(RemoteSecretReferences::substitute('x={{vault.A}} y={{vault.MISSING}}', $secrets))
        ->toBe('x=value-a y={{vault.MISSING}}');
});

test('reports missing keys', function () {
    expect(RemoteSecretReferences::missingKeys('{{vault.A}}-{{vault.B}}', ['A' => '1']))->toBe(['B'])
        ->and(RemoteSecretReferences::missingKeys('{{vault.A}}', ['A' => '1']))->toBe([]);
});

it('reads {{vault.KEY}} as the default source and {{vault.<source>.KEY}} as a named source', function () {
    $value = '{{vault.DB_PASSWORD}} {{ vault.urls.API_URL }} {{vault.my-src_2.KEY}} {{vault.default.OTHER}}';

    expect(RemoteSecretReferences::references($value))->toBe([
        ['source' => 'default', 'key' => 'DB_PASSWORD'],
        ['source' => 'urls', 'key' => 'API_URL'],
        ['source' => 'my-src_2', 'key' => 'KEY'],
        ['source' => 'default', 'key' => 'OTHER'],
    ])
        ->and(RemoteSecretReferences::referencedSources($value))->toBe(['default', 'urls', 'my-src_2'])
        ->and(RemoteSecretReferences::referencedKeys($value))->toBe(['DB_PASSWORD', 'urls.API_URL', 'my-src_2.KEY', 'OTHER']);
});

it('keeps a lowercase key without a source in the default source', function () {
    expect(RemoteSecretReferences::references('{{vault.api_key}}'))->toBe([['source' => 'default', 'key' => 'api_key']])
        ->and(RemoteSecretReferences::containsReference('{{vault.Urls.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{vault.-bad.KEY}}'))->toBeFalse()
        ->and(RemoteSecretReferences::containsReference('{{vault.a.b.KEY}}'))->toBeFalse();
});

it('substitutes and reports missing keys per source', function () {
    $secrets = [
        ...RemoteSecretReferences::labelSecrets('default', ['TOKEN' => 'default-token']),
        ...RemoteSecretReferences::labelSecrets('urls', ['TOKEN' => 'urls-token']),
    ];
    $value = '{{vault.TOKEN}}|{{vault.urls.TOKEN}}|{{vault.urls.MISSING}}';

    expect(RemoteSecretReferences::substitute($value, $secrets))->toBe('default-token|urls-token|{{vault.urls.MISSING}}')
        ->and(RemoteSecretReferences::missingKeys($value, $secrets))->toBe(['urls.MISSING'])
        ->and(RemoteSecretReferences::reference('default', 'KEY'))->toBe('{{vault.KEY}}')
        ->and(RemoteSecretReferences::reference('urls', 'KEY'))->toBe('{{vault.urls.KEY}}');
});

it('accepts only lowercase source names of up to 63 characters', function (mixed $name, bool $valid) {
    expect(RemoteSecretReferences::isValidSourceName($name))->toBe($valid);
})->with([
    ['default', true],
    ['urls', true],
    ['my-src_2', true],
    [str_repeat('a', 63), true],
    [str_repeat('a', 64), false],
    ['Urls', false],
    ['-urls', false],
    ['', false],
    [null, false],
]);
