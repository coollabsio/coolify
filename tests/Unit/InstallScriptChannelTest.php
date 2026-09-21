<?php

it('defaults nightly installations to the rolling next image while preserving explicit overrides', function () {
    $nightlyInstallScript = file_get_contents(base_path('other/nightly/install.sh'));

    expect($nightlyInstallScript)
        ->toContain('LATEST_VERSION=next')
        ->toMatch('/LATEST_VERSION=next.*?if \[ "\$1" != "" \].*?LATEST_VERSION=\$1/s')
        ->not->toContain("LATEST_VERSION=\$(echo \"\$VERSIONS_JSON\" | grep -i version | xargs | awk '{print \$2}' | tr -d ',')");
});

it('keeps stable installations on the manifest-selected version', function () {
    $stableInstallScript = file_get_contents(base_path('scripts/install.sh'));

    expect($stableInstallScript)
        ->toContain("LATEST_VERSION=\$(echo \"\$VERSIONS_JSON\" | grep -i version | xargs | awk '{print \$2}' | tr -d ',')")
        ->not->toContain('LATEST_VERSION=next');
});

it('derives the upgrade script from the persisted image channel with an explicit override', function () {
    $constants = file_get_contents(base_path('config/constants.php'));

    expect($constants)
        ->toContain("'latest_image' => env('LATEST_IMAGE', 'latest')")
        ->toContain("env('UPGRADE_SCRIPT_URL'")
        ->toContain("'/coolify-nightly/upgrade.sh'")
        ->toContain("'/coolify/upgrade.sh'");
});
