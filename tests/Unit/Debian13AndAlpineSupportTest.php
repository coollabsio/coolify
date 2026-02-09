<?php

namespace Tests\Unit;

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;
use Tests\TestCase;

/**
 * Tests for Debian 13 (Trixie) and Alpine Linux support.
 *
 * Verifies that the OS validation, prerequisites installation, and Docker
 * installation pipelines handle Debian 13 and Alpine correctly.
 *
 * @see https://github.com/coollabsio/coolify/issues/8154
 */
class Debian13AndAlpineSupportTest extends TestCase
{
    // ===== SUPPORTED_OS constant tests =====

    public function test_supported_os_includes_debian()
    {
        $debianFamily = collect(SUPPORTED_OS)->first(fn ($os) => str($os)->contains('debian'));
        $this->assertNotNull($debianFamily, 'SUPPORTED_OS should include a debian entry');
        $this->assertStringContainsString('debian', $debianFamily);
    }

    public function test_supported_os_includes_alpine()
    {
        $alpineEntry = collect(SUPPORTED_OS)->first(fn ($os) => str($os)->contains('alpine'));
        $this->assertNotNull($alpineEntry, 'SUPPORTED_OS should include an alpine entry');
    }

    // ===== InstallPrerequisites tests =====

    public function test_install_prerequisites_has_alpine_handler()
    {
        $reflection = new \ReflectionMethod(InstallPrerequisites::class, 'handle');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            "contains('alpine')",
            $source,
            'InstallPrerequisites should have an alpine handler'
        );
        $this->assertStringContainsString(
            'apk',
            $source,
            'InstallPrerequisites alpine handler should use apk package manager'
        );
    }

    public function test_install_prerequisites_debian_handler_uses_apt()
    {
        $source = file_get_contents(
            (new \ReflectionClass(InstallPrerequisites::class))->getFileName()
        );

        $this->assertStringContainsString(
            "contains('debian')",
            $source,
            'InstallPrerequisites should have a debian handler'
        );
        $this->assertStringContainsString(
            'apt-get update',
            $source,
            'InstallPrerequisites debian handler should use apt-get'
        );
    }

    // ===== InstallDocker tests =====

    public function test_install_docker_has_alpine_handler()
    {
        $source = file_get_contents(
            (new \ReflectionClass(InstallDocker::class))->getFileName()
        );

        $this->assertStringContainsString(
            "contains('alpine')",
            $source,
            'InstallDocker should have an alpine handler'
        );
    }

    public function test_install_docker_alpine_uses_apk()
    {
        $reflection = new \ReflectionMethod(InstallDocker::class, 'getAlpineDockerInstallCommand');
        $reflection->setAccessible(true);

        $docker = new InstallDocker;
        $command = $reflection->invoke($docker);

        $this->assertStringContainsString('apk', $command, 'Alpine Docker install should use apk');
        $this->assertStringContainsString('docker', $command, 'Alpine Docker install should install docker package');
        $this->assertStringContainsString('docker-cli-compose', $command, 'Alpine Docker install should install compose');
    }

    public function test_install_docker_alpine_uses_openrc_not_systemd()
    {
        $source = file_get_contents(
            (new \ReflectionClass(InstallDocker::class))->getFileName()
        );

        // The handle() method should use rc-update for Alpine instead of systemctl
        $this->assertStringContainsString(
            'rc-update add docker',
            $source,
            'InstallDocker should use rc-update for Alpine (OpenRC)'
        );
        $this->assertStringContainsString(
            'service docker restart',
            $source,
            'InstallDocker should use service command for Alpine (OpenRC)'
        );
    }

    public function test_install_docker_debian_has_codename_fallback()
    {
        $reflection = new \ReflectionMethod(InstallDocker::class, 'getDebianDockerInstallCommand');
        $reflection->setAccessible(true);

        // Need to set the private dockerVersion property
        $docker = new InstallDocker;
        $versionProp = new \ReflectionProperty(InstallDocker::class, 'dockerVersion');
        $versionProp->setAccessible(true);
        $versionProp->setValue($docker, '27.0');

        $command = $reflection->invoke($docker);

        // The command should have a codename fallback mechanism
        $this->assertStringContainsString(
            'DOCKER_CODENAME',
            $command,
            'Debian Docker install should use DOCKER_CODENAME variable for fallback'
        );
        $this->assertStringContainsString(
            'bookworm',
            $command,
            'Debian Docker install should fall back to bookworm when codename repo is unavailable'
        );
    }

    public function test_install_docker_debian_checks_repo_availability()
    {
        $reflection = new \ReflectionMethod(InstallDocker::class, 'getDebianDockerInstallCommand');
        $reflection->setAccessible(true);

        $docker = new InstallDocker;
        $versionProp = new \ReflectionProperty(InstallDocker::class, 'dockerVersion');
        $versionProp->setAccessible(true);
        $versionProp->setValue($docker, '27.0');

        $command = $reflection->invoke($docker);

        // Should check Docker repo availability before using the codename
        $this->assertStringContainsString(
            'download.docker.com/linux/debian/dists',
            $command,
            'Debian Docker install should check Docker repo availability for the codename'
        );
    }
}
