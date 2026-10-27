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

function parsedFinding(array $overrides = []): Finding
{
    return (new GrypeReportParser)->parse(grypeLine($overrides))['findings'][0];
}

it('reads the score, title, display ID, namespace, and match type of the postgres anchor', function () {
    $f = collect((new GrypeReportParser)->parse(imageScanFixture('grype.ndjson'))['findings'])->firstWhere('id', 'CVE-2025-1094');
    expect($f->cvssScore)->toBe(8.1)
        ->and($f->displayId)->toBe('CVE-2025-1094')
        ->and($f->title)->toStartWith('Improper neutralization of quoting syntax in PostgreSQL libpq functions')
        ->and(mb_strlen($f->title))->toBeLessThanOrEqual(120)
        ->and($f->namespace)->toBe('nvd:cpe')
        ->and($f->matchType)->toBe('cpe-match');
});

it('shows the CVE alias as the display ID of a GHSA finding and takes its own score before the NVD score', function () {
    $f = collect((new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'))['findings'])->firstWhere('id', 'GHSA-jfh8-c2jp-5v3q');
    expect($f->displayId)->toBe('CVE-2021-44228')
        ->and($f->cvssScore)->toBe(10.0)
        ->and($f->title)->toBe('Remote code injection in Log4j')
        ->and($f->namespace)->toBe('github:language:java')
        ->and($f->matchType)->toBe('exact-direct-match');

    $p6 = collect((new GrypeReportParser)->parse(imageScanFixture('grype-kev.ndjson'))['findings'])->firstWhere('id', 'GHSA-p6xc-xr62-6r2g');
    expect($p6->cvssScore)->toBe(8.6);
});

it('uses the finding ID as the display ID when no alias is a CVE', function (array $overrides, string $expected) {
    expect(parsedFinding($overrides)->displayId)->toBe($expected);
})->with([
    'own CVE id' => [['id' => 'CVE-2024-0001', 'rel' => ['GHSA-aaaa-bbbb-cccc']], 'CVE-2024-0001'],
    'alias' => [['id' => 'GHSA-aaaa-bbbb-cccc', 'rel' => ['GHSA-dddd-eeee-ffff', 'CVE-2023-1234']], 'CVE-2023-1234'],
    'none' => [['id' => 'GHSA-aaaa-bbbb-cccc', 'rel' => ['GO-2023-1']], 'GHSA-aaaa-bbbb-cccc'],
]);

it('picks the score from the own source, then NVD, then the highest CVSS version, and ignores v2', function (array $overrides, ?float $expected) {
    $nvd = ['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 9.8];
    $own = ['source' => 'alpine', 'version' => '3.1', 'score' => 5.3];
    $base = ['ns' => 'alpine:distro:alpine:3.19', 'cvss' => [$nvd, $own], 'rcvss' => []];
    expect(parsedFinding([...$base, ...$overrides])->cvssScore)->toBe($expected);
})->with([
    'own source before NVD' => [[], 5.3],
    'v4 before v3.1 in the same source' => [['cvss' => [
        ['source' => 'x', 'version' => '3.1', 'score' => 9.0], ['source' => 'x', 'version' => '4.0', 'score' => 7.1],
    ]], 7.1],
    'v3.1 before v3.0' => [['cvss' => [
        ['source' => 'x', 'version' => '3.0', 'score' => 9.0], ['source' => 'x', 'version' => '3.1', 'score' => 6.1],
    ]], 6.1],
    'NVD when the own source has none' => [['cvss' => [['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 9.8]]], 9.8],
    'NVD from the related vulnerability' => [['cvss' => [], 'rcvss' => [['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 7.4]]], 7.4],
    'related non-NVD source is not used' => [['cvss' => [], 'rcvss' => [['source' => 'other', 'version' => '3.1', 'score' => 7.4]]], null],
    'v2 only' => [['cvss' => [['source' => 'x', 'version' => '2.0', 'score' => 9.3]], 'rcvss' => []], null],
    'own v2 falls back to NVD v3' => [['cvss' => [
        ['source' => 'x', 'version' => '2.0', 'score' => 9.3], ['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 7.4],
    ]], 7.4],
    'no entries' => [['cvss' => [], 'rcvss' => []], null],
    'zero score is no score' => [['cvss' => [['source' => 'x', 'version' => '3.1', 'score' => 0], ['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 9.8]]], 9.8],
    'string score ignored' => [['cvss' => [['source' => 'x', 'version' => '3.1', 'score' => 'high'], ['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 9.8]]], 9.8],
]);

it('treats every entry of an NVD namespace finding as its own source', function () {
    $f = parsedFinding(['ns' => 'nvd:cpe', 'cvss' => [
        ['source' => '134c704f-9b21', 'version' => '3.1', 'score' => 7.4],
        ['source' => 'nvd@nist.gov', 'version' => '3.1', 'score' => 7.5],
    ]]);
    expect($f->cvssScore)->toBe(7.5);
    expect(parsedFinding(['ns' => 'nvd:cpe', 'cvss' => [['source' => 'f86ef6dc', 'version' => '3.1', 'score' => 8.1]]])->cvssScore)->toBe(8.1);
});

it('has no score when the line has no cvss data', function () {
    expect(parsedFinding()->cvssScore)->toBeNull();
});

it('splits the title at the first sentence end followed by a capital letter', function () {
    $f = parsedFinding(['desc' => 'In OpenSSL before 3.0.15, the X.400 type handling failed. Second sentence here.']);
    expect($f->title)->toBe('In OpenSSL before 3.0.15, the X.400 type handling failed.');
    expect(parsedFinding(['desc' => 'Is this a problem? Yes it is a big problem.'])->title)->toBe('Is this a problem?');
    expect(parsedFinding(['desc' => 'A heap overflow in the parser. lower case continues here and more words'])->title)
        ->toBe('A heap overflow in the parser. lower case continues here and more words');
});

it('cuts a long title at a word boundary and adds an ellipsis within 120 characters', function () {
    $f = parsedFinding(['desc' => str_repeat('overflow ', 40)]);
    expect(mb_strlen($f->title))->toBeLessThanOrEqual(120)
        ->and($f->title)->toEndWith('overflow…')
        ->and($f->title)->not->toContain('  ');
    $long = parsedFinding(['desc' => str_repeat('a', 100).str_repeat(' bb', 40)]);
    expect(mb_strlen($long->title))->toBeLessThanOrEqual(120)->and($long->title)->toEndWith('bb…');
    expect(parsedFinding(['desc' => str_repeat('a', 300).' b c d', 'rdesc' => ''])->title)->toBe('openssl 1.0: high vulnerability');
});

it('removes Markdown, URLs, and control and format characters from the title', function () {
    expect(parsedFinding(['desc' => 'Fixes [the parser bug](https://e.com/x) in `libfoo` with **bold** and _emphasis_ at https://example.org/a?b=1 now'])->title)
        ->toBe('Fixes the parser bug in libfoo with bold and emphasis at now');
    expect(parsedFinding(['desc' => "Evil \u{202E}title with\u{200B} hidden\ncharacters and more"])->title)
        ->toBe('Evil title with hidden characters and more');
    expect(parsedFinding(['desc' => 'Reads client_encoding from PQescapeLiteral() calls today'])->title)
        ->toBe('Reads client_encoding from PQescapeLiteral() calls today');
});

it('uses the package fallback title for fewer than four words', function (string $desc) {
    expect(parsedFinding(['desc' => $desc, 'rdesc' => '', 'pkg' => 'openssl', 'ver' => '1.0', 'sev' => 'High'])->title)
        ->toBe('openssl 1.0: high vulnerability');
})->with(['empty' => '', 'two words' => 'Buffer overflow', 'markdown only' => '**[x](https://e.com)**', 'three words' => 'Heap buffer overflow']);

it('uses the related description when the description is empty', function () {
    $f = parsedFinding(['desc' => '', 'rdesc' => 'Related vulnerability allows remote code execution']);
    expect($f->title)->toBe('Related vulnerability allows remote code execution');
    $g = parsedFinding(['desc' => 'Own description wins over the related one', 'rdesc' => 'Related vulnerability allows remote code execution']);
    expect($g->title)->toBe('Own description wins over the related one');
});

it('keeps the 300 character summary independent of the title', function () {
    $f = parsedFinding(['desc' => str_repeat('d', 400)]);
    expect(strlen($f->summary))->toBe(300);
});

it('reads old arrays without the new keys and round trips them', function () {
    $old = [
        'id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2023-1234'], 'severity' => 'high', 'package' => 'openssl', 'version' => '1.0',
        'type' => 'apk', 'artifactId' => 'abc', 'fixedIn' => '1.1', 'fixState' => 'fixed', 'kev' => false, 'epss' => null,
        'risk' => null, 'summary' => 'x', 'layer' => 'app',
    ];
    $f = Finding::fromArray($old);
    expect($f->cvssScore)->toBeNull()
        ->and($f->title)->toBe('openssl 1.0: high vulnerability')
        ->and($f->displayId)->toBe('CVE-2023-1234')
        ->and($f->namespace)->toBe('')
        ->and($f->matchType)->toBe('')
        ->and(Finding::fromArray($f->toArray()))->toEqual($f);

    expect(Finding::fromArray([...$old, 'id' => 'CVE-2024-1', 'aliases' => []])->displayId)->toBe('CVE-2024-1');
});

it('round trips the new fields', function () {
    $f = parsedFinding(['ns' => 'alpine:distro:alpine:3.19', 'mt' => 'exact-indirect-match', 'cvss' => [['source' => 'a', 'version' => '3.1', 'score' => 6.5]], 'desc' => 'Something dangerous happens in the library']);
    $array = $f->toArray();
    expect($array['cvssScore'])->toBe(6.5)->and($array['title'])->toBe('Something dangerous happens in the library')
        ->and($array['displayId'])->toBe('CVE-2024-0001')->and($array['namespace'])->toBe('alpine:distro:alpine:3.19')
        ->and($array['matchType'])->toBe('exact-indirect-match')
        ->and(Finding::fromArray($array))->toEqual($f)
        ->and($f->withLayer('base')->cvssScore)->toBe(6.5);
});
