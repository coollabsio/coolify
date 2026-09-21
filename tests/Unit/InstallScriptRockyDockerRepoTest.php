<?php

function expectRockyInstallScriptToUseRhelRepo(string $path): void
{
    $installScript = file_get_contents(base_path($path));

    expect($installScript)
        ->toContain('install_docker_from_rhel_repo() {')
        ->toContain('echo " - Installing Docker from the RHEL repository for Rocky Linux..."')
        ->toContain('rm -f /etc/yum.repos.d/docker-ce.repo /etc/yum.repos.d/docker-ce-staging.repo')
        ->toContain('dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo')
        ->toContain('dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin')
        ->toContain('systemctl --now enable docker')
        ->toContain('"rocky")')
        ->toContain('install_docker_from_rhel_repo')
        ->not->toContain('dnf -y -q --setopt=install_weak_deps=False install dnf-plugins-core')
        ->not->toContain('dnf5 config-manager addrepo --overwrite --save-filename=docker-ce.repo --from-repofile=https://download.docker.com/linux/rhel/docker-ce.repo')
        ->not->toContain('dnf makecache')
        ->not->toContain('"ubuntu" | "debian" | "raspbian" | "centos" | "fedora" | "rhel" | "rocky" | "sles")');
}

it('uses the rocky linux documented docker install flow in the stable install script', function () {
    expectRockyInstallScriptToUseRhelRepo('scripts/install.sh');
});

it('uses the rocky linux documented docker install flow in the nightly install script', function () {
    expectRockyInstallScriptToUseRhelRepo('other/nightly/install.sh');
});

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
