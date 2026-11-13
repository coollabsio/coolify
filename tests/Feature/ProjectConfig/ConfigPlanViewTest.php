<?php

use App\Services\ProjectConfig\ConfigPlanView;

function planViewChange(string $kind, string $key, string $path = '', bool $destructive = false, mixed $from = null, mixed $to = null, bool $secret = false): array
{
    return compact('kind', 'key', 'path', 'destructive', 'from', 'to', 'secret');
}

it('groups changes by resource, in plan order, with a tag each', function () {
    $groups = ConfigPlanView::groups([
        planViewChange('create', 'cache', to: 'redis'),
        planViewChange('update', 'web', 'ports.exposes', from: '80', to: '8080'),
        planViewChange('update', 'web', 'env.TOKEN', secret: true),
        planViewChange('remove', 'worker', '', true, 'application'),
        planViewChange('rename', 'api', from: 'web-old', to: 'api'),
        planViewChange('import', 'db', to: 'k8s0abc'),
        planViewChange('unmanage', 'legacy'),
    ]);

    expect(array_column($groups, 'key'))->toBe(['cache', 'web', 'worker', 'api', 'db', 'legacy'])
        ->and(array_column($groups, 'tag'))->toBe(['New', 'Changed', 'Removed', 'Renamed', 'Adopted', 'No longer managed'])
        ->and(array_column($groups, 'destructive'))->toBe([false, false, true, false, false, false])
        ->and($groups[1]['fields'])->toHaveCount(2)
        ->and($groups[1]['fields'][1])->toMatchArray(['path' => 'env.TOKEN', 'value' => 'changed'])
        ->and($groups[1]['fields'][0]['value'])->toBe('80 → 8080')
        ->and($groups[3]['detail'])->toBe('from web-old');
});

it('marks a resource whose type changes as replaced', function () {
    $groups = ConfigPlanView::groups([
        planViewChange('remove', 'db', '', true, 'postgresql'),
        planViewChange('create', 'db', to: 'mysql'),
    ]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['key' => 'db', 'tag' => 'Replaced', 'destructive' => true, 'removes' => true]);
});

it('shows lists, objects, and removed values in a short form', function () {
    $fields = ConfigPlanView::groups([
        planViewChange('update', 'web', 'domains', from: ['https://a.test'], to: ['https://a.test', 'https://b.test']),
        planViewChange('update', 'web', 'health_check', from: ['retries' => 3], to: null),
        planViewChange('update', 'web', 'storages.data', true, ['mount' => '/data'], null),
    ])[0]['fields'];

    expect(array_column($fields, 'value'))->toBe([
        'https://a.test → https://a.test, https://b.test',
        'retries: 3 → default',
        'mount: /data → removed',
    ])->and($fields[2]['destructive'])->toBeTrue();
});
