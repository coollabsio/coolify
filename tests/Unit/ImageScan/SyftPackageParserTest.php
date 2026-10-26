<?php

use App\Services\ImageScan\Package;
use App\Services\ImageScan\SyftPackageParser;

function syftLine(array $overrides = []): string
{
    return json_encode(array_merge([
        'id' => 'abc123', 'name' => 'openssl', 'version' => '1.0', 'type' => 'apk',
        'licenses' => ['MIT'], 'purl' => 'pkg:apk/alpine/openssl@1.0', 'layer' => 'sha256:'.str_repeat('a', 64),
    ], $overrides));
}

it('parses the postgres 15.0 package list', function () {
    $r = (new SyftPackageParser)->parse(file_get_contents(dirname(__DIR__, 2).'/Fixtures/image-scan/syft-packages.ndjson'));
    expect($r['packages'])->toHaveCount(46)->and($r['truncated'])->toBeFalse();
    $p = $r['packages'][0];
    expect($p)->toBeInstanceOf(Package::class)
        ->and($p->name)->toBe('.postgresql-rundeps')->and($p->type)->toBe('apk')
        ->and($p->layerId)->toStartWith('sha256:')->and($p->licenses)->toBe([])
        ->and($p->purl)->toStartWith('pkg:apk/alpine/.postgresql-rundeps@')
        ->and($r['layerIds'])->toHaveCount(8)
        ->and($r['layerIds'][0])->toBe('sha256:994393dc58e7931862558d06e46aa2bb17487044f670f310dffe1d24e4d1eec7')
        ->and($r['layerIds'][3])->toBe('sha256:427eca9eca256d5db3002eb8993b59d663e44ac34a8098f3dcfe151c8bb32d54');
    expect(collect($r['packages'])->pluck('layerId')->unique()->all())->each->toBeIn($r['layerIds']);
});

it('does not count the layers trailer as a package', function () {
    $r = (new SyftPackageParser)->parse(syftLine()."\n".'{"layers":["sha256:1","sha256:2"]}');
    expect($r['packages'])->toHaveCount(1)->and($r['layerIds'])->toBe(['sha256:1', 'sha256:2']);
});

it('caps packages and marks truncation', function () {
    $r = (new SyftPackageParser)->parse(file_get_contents(dirname(__DIR__, 2).'/Fixtures/image-scan/syft-packages.ndjson'), 10);
    expect($r['packages'])->toHaveCount(10)->and($r['truncated'])->toBeTrue()->and($r['layerIds'])->toHaveCount(8);
});

it('drops invalid lines, strips control characters and cuts long strings', function () {
    $good = syftLine(['name' => "a\u{1b}b\n", 'licenses' => ["MIT\u{1b}", ['x'], ''], 'version' => str_repeat('v', 300)]);
    $r = (new SyftPackageParser)->parse("not json\n{$good}\n".json_encode(['name' => 'no id']));
    expect($r['packages'])->toHaveCount(1)
        ->and($r['packages'][0]->name)->toBe('ab')
        ->and($r['packages'][0]->licenses)->toBe(['MIT'])
        ->and(strlen($r['packages'][0]->version))->toBe(128);
});

it('rejects input over 10 MB as truncated and empty', function () {
    $r = (new SyftPackageParser)->parse(str_repeat('a', 10 * 1024 * 1024 + 1));
    expect($r)->toBe(['packages' => [], 'truncated' => true, 'layerIds' => []]);
});

it('round trips a package through toArray and fromArray', function () {
    $p = (new SyftPackageParser)->parse(syftLine())['packages'][0];
    expect(Package::fromArray($p->toArray()))->toEqual($p);
});
