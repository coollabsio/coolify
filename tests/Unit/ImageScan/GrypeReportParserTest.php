<?php

use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;

function imageScanFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 2).'/Fixtures/image-scan/'.$name);
}

function grypeLine(array $overrides = []): string
{
    return json_encode(array_merge([
        'id' => 'CVE-2024-0001', 'rel' => [], 'sev' => 'High', 'pkg' => 'openssl', 'ver' => '1.0',
        'type' => 'apk', 'art' => 'abc', 'fix' => 'fixed', 'fixv' => ['1.1'], 'kev' => false, 'desc' => 'x',
    ], $overrides));
}

it('parses the postgres 15.0 report', function () {
    $r = (new GrypeReportParser)->parse(imageScanFixture('grype.ndjson'));
    expect($r['findings'])->toHaveCount(166)
        ->and($r['truncated'])->toBeFalse()
        ->and($r['eol'])->toBe(['name' => 'alpine', 'version' => '3.16.2']);
    $f = collect($r['findings'])->firstWhere('id', 'CVE-2025-1094');
    expect($f)->toBeInstanceOf(Finding::class)
        ->and($f->severity)->toBe('high')->and($f->fixedIn)->toBe('15.11')->and($f->fixState)->toBe('fixed')
        ->and($f->epss)->toBe(0.9003)->and($f->kev)->toBeFalse()->and($f->package)->toBe('postgresql')
        ->and($f->version)->toBe('15.0')->and($f->type)->toBe('binary')->and($f->artifactId)->toBe('0bbf5c9dd36d4a50')
        ->and($f->risk)->toBe(70.2234)->and($f->layer)->toBe('app')->and($f->aliases)->toBe([]);
});

it('reads known exploited and aliases', function () {
    $f = collect((new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'))['findings'])
        ->firstWhere('id', 'GHSA-jfh8-c2jp-5v3q');
    expect($f->kev)->toBeTrue()->and($f->aliases)->toBe(['CVE-2021-44228'])->and($f->severity)->toBe('critical');
});

it('returns a null eol when the report has no distro alert', function () {
    $r = (new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'));
    expect($r['eol'])->toBeNull();
});

it('does not count the eol trailer as a finding', function () {
    $r = (new GrypeReportParser)->parse(grypeLine()."\n".'{"eol":{"name":"alpine","version":"3.16"}}');
    expect($r['findings'])->toHaveCount(1)->and($r['eol'])->toBe(['name' => 'alpine', 'version' => '3.16']);
});

it('caps findings and marks truncation', function () {
    $r = (new GrypeReportParser)->parse(imageScanFixture('grype.ndjson'), 10);
    expect($r['findings'])->toHaveCount(10)->and($r['truncated'])->toBeTrue();
});

it('is not truncated when the count equals the limit', function () {
    $r = (new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'), 7);
    expect($r['findings'])->toHaveCount(7)->and($r['truncated'])->toBeFalse();
});

it('still reads the eol trailer after the cap is reached', function () {
    $r = (new GrypeReportParser)->parse(imageScanFixture('grype.ndjson'), 10);
    expect($r['eol'])->toBe(['name' => 'alpine', 'version' => '3.16.2']);
});

it('drops invalid lines and strips control characters', function () {
    $line = grypeLine(['id' => "CVE-1\u{1b}", 'pkg' => "a\nb\u{1b}[31m", 'fixv' => []]);
    $r = (new GrypeReportParser)->parse("not json\n{$line}\n".json_encode(['id' => '../../etc']));
    expect($r['findings'])->toHaveCount(1)
        ->and($r['findings'][0]->package)->toBe('ab[31m')
        ->and($r['findings'][0]->id)->toBe('CVE-1');
});

it('drops lines whose id does not match the id pattern', function (string $id) {
    $r = (new GrypeReportParser)->parse(grypeLine(['id' => $id]));
    expect($r['findings'])->toBe([]);
})->with(['path' => '../../etc', 'empty' => '', 'shell' => 'CVE-1; rm -rf /', 'ansi' => "CVE-1\u{1b}[31m", 'too long' => str_repeat('A', 65), 'leading dash' => '-CVE']);

it('maps severities to lowercase and unknown values to unknown', function (string $raw, string $expected) {
    $r = (new GrypeReportParser)->parse(grypeLine(['sev' => $raw]));
    expect($r['findings'][0]->severity)->toBe($expected);
})->with([['Critical', 'critical'], ['HIGH', 'high'], ['Medium', 'medium'], ['Low', 'low'], ['Negligible', 'low'], ['', 'unknown'], ['weird', 'unknown']]);

it('cuts strings longer than their caps', function () {
    $r = (new GrypeReportParser)->parse(grypeLine([
        'pkg' => str_repeat('p', 300), 'ver' => str_repeat('v', 300), 'desc' => str_repeat('d', 400),
    ]));
    $f = $r['findings'][0];
    expect(strlen($f->package))->toBe(214)->and(strlen($f->version))->toBe(128)->and(strlen($f->summary))->toBe(300);
});

it('uses the suggested version, then the first fix version when fixed, otherwise null', function () {
    $parse = fn (array $o) => (new GrypeReportParser)->parse(grypeLine($o))['findings'][0]->fixedIn;
    expect($parse(['sugg' => '2.0', 'fixv' => ['1.1']]))->toBe('2.0')
        ->and($parse(['fixv' => ['1.1', '1.2']]))->toBe('1.1')
        ->and($parse(['fix' => 'not-fixed', 'fixv' => ['1.1']]))->toBeNull()
        ->and($parse(['fix' => 'fixed', 'fixv' => []]))->toBeNull();
});

it('leaves epss and risk null when absent', function () {
    $f = (new GrypeReportParser)->parse(grypeLine())['findings'][0];
    expect($f->epss)->toBeNull()->and($f->risk)->toBeNull();
});

it('rejects input over 10 MB as truncated and empty', function () {
    $r = (new GrypeReportParser)->parse(str_repeat('a', 10 * 1024 * 1024 + 1));
    expect($r)->toBe(['findings' => [], 'truncated' => true, 'eol' => null]);
});

it('ignores lines nested deeper than the allowed depth', function () {
    $deep = str_repeat('[', 20).str_repeat(']', 20);
    $r = (new GrypeReportParser)->parse($deep."\n".grypeLine());
    expect($r['findings'])->toHaveCount(1);
});

it('keeps the other fields when setting the layer', function () {
    $f = (new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'))['findings'][0];
    $base = $f->withLayer('base');
    expect($base->layer)->toBe('base')->and([...$base->toArray(), 'layer' => 'app'])->toBe($f->toArray());
});

it('round trips a finding through toArray and fromArray', function () {
    $f = (new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'))['findings'][0];
    expect(Finding::fromArray($f->toArray()))->toEqual($f);
});

it('parses the candidate reports', function () {
    $a = (new GrypeReportParser)->parse(imageScanFixture('grype-candidate-15.11.ndjson'));
    $b = (new GrypeReportParser)->parse(imageScanFixture('grype-candidate-15-alpine.ndjson'));
    expect($a['findings'])->not->toBeEmpty()->and($b['findings'])->toHaveCount(64)
        ->and(collect($a['findings'])->where('severity', 'critical')->count())->toBe(20);
});
