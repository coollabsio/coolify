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
