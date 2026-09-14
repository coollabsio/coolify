<?php

use App\Services\CoolifyVersionSelector;

function coolifyVersions(string $stable = '4.4.0', string $rc = '4.5-rc.1', array $minors = ['4.3' => '4.3.11', '4.4' => '4.4.0']): array
{
    return [
        'coolify' => [
            'v4' => [
                'version' => $stable,
                'minors' => $minors,
            ],
            'rc' => ['version' => $rc],
        ],
    ];
}

it('selects stable releases for the stable manual channel', function () {
    expect(CoolifyVersionSelector::forManual(coolifyVersions(), '4.3.10', 'stable'))->toBe('4.4.0');
});

it('selects the newest stable or RC release for the RC manual channel', function () {
    expect(CoolifyVersionSelector::forManual(coolifyVersions(), '4.3.10', 'rc'))->toBe('4.5-rc.1')
        ->and(CoolifyVersionSelector::forManual(coolifyVersions(stable: '4.5.0', rc: '4.5-rc.1'), '4.4.0', 'rc'))->toBe('4.5.0');
});

it('never selects a manual downgrade', function () {
    expect(CoolifyVersionSelector::forManual(coolifyVersions(stable: '4.3.10', rc: '4.4-rc.1'), '4.4-rc.2', 'stable'))->toBe('4.4-rc.2');
});

it('selects stable releases only for automatic updates', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(), '4.3.10', 'minor'))->toBe('4.4.0');
});

it('keeps patch-only automatic updates on the installed minor line', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(), '4.3.10', 'patch'))->toBe('4.3.11');
});

it('does nothing when patch metadata for the installed minor line is missing', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(minors: ['4.4' => '4.4.0']), '4.3.10', 'patch'))->toBe('4.3.10');
});

it('allows an RC to graduate to a stable release in the same minor line', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(), '4.4-rc.1', 'patch'))->toBe('4.4.0');
});

it('never selects an automatic downgrade', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(stable: '4.3.10'), '4.4-rc.1', 'minor'))->toBe('4.4-rc.1');
});

it('rejects RC versions published in stable automatic-update metadata', function () {
    expect(CoolifyVersionSelector::forAutomatic(coolifyVersions(stable: '4.4-rc.2'), '4.3.10', 'minor'))->toBe('4.3.10')
        ->and(CoolifyVersionSelector::forAutomatic(coolifyVersions(minors: ['4.3' => '4.3-rc.2']), '4.3.10', 'patch'))->toBe('4.3.10');
});

it('reconciles newer cached versions with CDN metadata', function () {
    $cdn = coolifyVersions(stable: '4.3.10', rc: '4.4-rc.1', minors: ['4.3' => '4.3.10']);
    $cached = coolifyVersions(stable: '4.3.11', rc: '4.4-rc.2', minors: ['4.3' => '4.3.11']);

    $versions = CoolifyVersionSelector::reconcileMetadata($cdn, $cached, '4.3.10');

    expect(data_get($versions, 'coolify.v4.version'))->toBe('4.3.11')
        ->and(data_get($versions, 'coolify.rc.version'))->toBe('4.4-rc.2')
        ->and($versions['coolify']['v4']['minors']['4.3'])->toBe('4.3.11');
});

it('rejects invalid persisted update preferences', function () {
    expect(fn () => CoolifyVersionSelector::forManual(coolifyVersions(), '4.3.10', 'nightly'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => CoolifyVersionSelector::forAutomatic(coolifyVersions(), '4.3.10', 'major'))
        ->toThrow(InvalidArgumentException::class);
});
