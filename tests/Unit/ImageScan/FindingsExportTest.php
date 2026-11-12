<?php

use App\Models\ImageScanIgnore;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\FindingsExport;
use Tests\TestCase;

uses(TestCase::class);

function exportRow(array $overrides = [], string $image = 'nginx:1.27', ?ImageScanIgnore $ignore = null): array
{
    $finding = new Finding(...[
        'id' => 'CVE-2024-0001', 'aliases' => ['CVE-2024-0001', 'GHSA-aaaa-bbbb-cccc'], 'severity' => 'High', 'package' => 'openssl',
        'version' => '3.0.1', 'type' => 'apk', 'artifactId' => 'a1', 'fixedIn' => '3.0.2', 'fixState' => 'fixed', 'kev' => false,
        'epss' => 0.2, 'risk' => 1.0, 'summary' => 'A bug.', ...$overrides,
    ]);

    return ['finding' => $finding, 'state' => 'fixed', 'ignore' => $ignore, 'image' => $image];
}

it('escapes Markdown table cells and adds the image and ignore columns only when asked', function () {
    $ignore = new ImageScanIgnore(['reason' => "Not used | test\nonly"]);
    $rows = [exportRow(['package' => 'a|b']), exportRow(['id' => 'ALPINE-1', 'fixedIn' => null], 'redis:7', $ignore)];

    $plain = FindingsExport::markdown('Title', [$rows[0]], false, false);
    $full = FindingsExport::markdown('Title', $rows, true, true);

    expect($plain)->toContain("| ID | Severity | Package | Version | Fixed in |\n")
        ->toContain('| a\\|b |')
        ->and($full)->toContain('| ID | Severity | Package | Version | Fixed in | Image | Ignored because |')
        ->toContain('| ALPINE-1 | High | openssl | 3.0.1 |  | redis:7 | Not used \\| test only |');
});

it('lists each ID once', function () {
    expect(FindingsExport::ids([exportRow(), exportRow(), exportRow(['id' => 'CVE-2024-0002'])]))->toBe("CVE-2024-0001\nCVE-2024-0002\n")
        ->and(FindingsExport::ids([]))->toBe('');
});

it('stops a spreadsheet from running scan data as a formula', function () {
    $csv = FindingsExport::csv([exportRow(['package' => '=HYPERLINK("http://x")', 'summary' => '@SUM(1)'])], ['fixed' => 'Fix available upstream']);
    $row = str_getcsv(explode("\n", $csv)[1], escape: '');

    expect($row[3])->toBe('\'=HYPERLINK("http://x")')
        ->and($row[15])->toBe("'@SUM(1)")
        ->and($row[8])->toBe('Fix available upstream')
        ->and($row[11])->toBe('GHSA-aaaa-bbbb-cccc');
});

it('writes the resource and every finding to JSON', function () {
    $json = json_decode(FindingsExport::json(['type' => 'application', 'name' => 'web', 'uuid' => 'u1'], [exportRow(['kev' => true])], ['fixed' => 'Fix available upstream']), true);

    expect($json['resource']['name'])->toBe('web')
        ->and($json['count'])->toBe(1)
        ->and($json['findings'][0])->toMatchArray([
            'id' => 'CVE-2024-0001', 'aliases' => ['GHSA-aaaa-bbbb-cccc'], 'severity' => 'High', 'known_exploited' => true,
            'fixed_in' => '3.0.2', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-0001', 'ignored' => null,
        ]);
});
