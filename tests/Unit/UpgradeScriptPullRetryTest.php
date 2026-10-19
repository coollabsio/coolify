<?php

/**
 * Runs pull_image() from the upgrade script with a fake docker that fails the first $failures pulls.
 *
 * @return array{exitCode: int, pulls: int}
 */
function runUpgradeScriptPullImage(string $script, int $failures): array
{
    $dir = sys_get_temp_dir().'/upgrade-pull-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $source = file_get_contents(getcwd().'/'.$script);
    preg_match('/^log\(\) \{.*?^\}\n/ms', $source, $log);
    preg_match('/^pull_image\(\) \{.*?^\}\n/ms', $source, $pullImage);

    $harness = <<<BASH
        LOGFILE="{$dir}/upgrade.log"
        docker() {
            local count=\$(cat "{$dir}/pulls" 2>/dev/null || echo 0)
            echo \$((count + 1)) > "{$dir}/pulls"
            [ "\$count" -ge {$failures} ]
        }
        sleep() { :; }
        {$log[0]}
        {$pullImage[0]}
        pull_image ghcr.io/coollabsio/coolify-helper:1.0.14
        BASH;

    exec('bash -c '.escapeshellarg($harness).' >/dev/null 2>&1', result_code: $exitCode);
    $pulls = (int) @file_get_contents("{$dir}/pulls");
    exec('rm -rf '.escapeshellarg($dir));

    return ['exitCode' => $exitCode, 'pulls' => $pulls];
}

it('retries a rate limited image pull until it succeeds', function (string $script) {
    expect(runUpgradeScriptPullImage($script, failures: 2))->toBe(['exitCode' => 0, 'pulls' => 3]);
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);

it('fails the image pull after five attempts', function (string $script) {
    expect(runUpgradeScriptPullImage($script, failures: 10))->toBe(['exitCode' => 1, 'pulls' => 5]);
})->with(['scripts/upgrade.sh', 'other/nightly/upgrade.sh']);
