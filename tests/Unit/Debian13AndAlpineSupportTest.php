<?php

namespace Tests\Unit;

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for Debian 13 (Trixie) and Alpine Linux support.
 *
 * Issue: https://github.com/coollabsio/coolify/issues/8154
 *
 * This test suite verifies:
 * 1. Debian 13 (trixie) detection and Docker installation with codename fallback
 * 2. Alpine Linux prerequisites and Docker installation
 * 3. OS detection via ID and ID_LIKE fallback
 */
class Debian13AndAlpineSupportTest extends TestCase
{
    /**
     * Test that SUPPORTED_OS constant includes debian and alpine.
     */
    public function test_supported_os_includes_debian_and_alpine(): void
    {
        $supportedOs = collect(SUPPORTED_OS);

        // Check debian is in the first group
        $this->assertTrue(
            $supportedOs->contains(fn ($os) => str_contains($os, 'debian')),
            'SUPPORTED_OS should include debian'
        );

        // Check alpine is supported
        $this->assertTrue(
            $supportedOs->contains(fn ($os) => str_contains($os, 'alpine')),
            'SUPPORTED_OS should include alpine'
        );
    }

    /**
     * Test that InstallPrerequisites handles Alpine Linux.
     */
    public function test_install_prerequisites_has_alpine_handler(): void
    {
        $reflection = new ReflectionClass(InstallPrerequisites::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            "contains('alpine')",
            $source,
            'InstallPrerequisites should have an Alpine handler'
        );

        $this->assertStringContainsString(
            'apk',
            $source,
            'InstallPrerequisites should use apk for Alpine'
        );
    }

    /**
     * Test that InstallDocker has Alpine-specific installation method.
     */
    public function test_install_docker_has_alpine_handler(): void
    {
        $reflection = new ReflectionClass(InstallDocker::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            "contains('alpine')",
            $source,
            'InstallDocker should check for Alpine OS type'
        );

        $this->assertStringContainsString(
            'getAlpineDockerInstallCommand',
            $source,
            'InstallDocker should have getAlpineDockerInstallCommand method'
        );
    }

    /**
     * Test that InstallDocker uses OpenRC for Alpine instead of systemd.
     */
    public function test_install_docker_uses_openrc_for_alpine(): void
    {
        $reflection = new ReflectionClass(InstallDocker::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'rc-update',
            $source,
            'InstallDocker should use rc-update for Alpine (OpenRC)'
        );

        $this->assertStringContainsString(
            'rc-service',
            $source,
            'InstallDocker should support rc-service for Alpine'
        );
    }

    /**
     * Test that Debian Docker installation has dynamic codename fallback.
     */
    public function test_debian_docker_install_has_codename_fallback(): void
    {
        $reflection = new ReflectionClass(InstallDocker::class);
        $source = file_get_contents($reflection->getFileName());

        // Check for dynamic fallback mechanism
        $this->assertStringContainsString(
            'DOCKER_CODENAME',
            $source,
            'InstallDocker should use DOCKER_CODENAME variable for dynamic fallback'
        );

        $this->assertStringContainsString(
            'bookworm',
            $source,
            'InstallDocker should fallback to bookworm when codename not available'
        );

        // Check that it tests the Docker repo availability
        $this->assertStringContainsString(
            'download.docker.com',
            $source,
            'InstallDocker should check Docker repo availability'
        );
    }

    /**
     * Test that the Alpine Docker install command uses correct packages.
     */
    public function test_alpine_docker_install_command_content(): void
    {
        $installDocker = new InstallDocker();

        $reflection = new ReflectionClass($installDocker);
        $method = $reflection->getMethod('getAlpineDockerInstallCommand');
        $method->setAccessible(true);

        $command = $method->invoke($installDocker);

        $this->assertStringContainsString('apk', $command, 'Should use apk package manager');
        $this->assertStringContainsString('docker', $command, 'Should install docker package');
        $this->assertStringContainsString('docker-cli-compose', $command, 'Should install docker-cli-compose');
        $this->assertStringContainsString('rc-update', $command, 'Should use rc-update for service management');
    }

    /**
     * Test that Debian Docker install command includes fallback logic.
     */
    public function test_debian_docker_install_command_has_fallback_logic(): void
    {
        $installDocker = new InstallDocker();

        $reflection = new ReflectionClass($installDocker);
        $method = $reflection->getMethod('getDebianDockerInstallCommand');
        $method->setAccessible(true);

        // We need to set the dockerVersion property first
        $versionProperty = $reflection->getProperty('dockerVersion');
        $versionProperty->setAccessible(true);
        $versionProperty->setValue($installDocker, '27.0');

        $command = $method->invoke($installDocker);

        // Should check if codename exists in Docker repo
        $this->assertStringContainsString('curl', $command, 'Should use curl to check repo');
        $this->assertStringContainsString('DOCKER_CODENAME', $command, 'Should use dynamic codename variable');
        $this->assertStringContainsString('bookworm', $command, 'Should fallback to bookworm');
    }

    /**
     * Test that unsupported OS types still throw appropriate exception.
     */
    public function test_unsupported_os_throws_exception_with_context(): void
    {
        $reflection = new ReflectionClass(InstallPrerequisites::class);
        $source = file_get_contents($reflection->getFileName());

        // The error message should include the detected OS type for debugging
        $this->assertStringContainsString(
            'Unsupported OS type',
            $source,
            'Should throw exception for unsupported OS'
        );
    }

    /**
     * Test Server model validateOS method supports ID_LIKE fallback.
     */
    public function test_server_validate_os_has_id_like_support(): void
    {
        $serverModelPath = base_path('app/Models/Server.php');
        $source = file_get_contents($serverModelPath);

        $this->assertStringContainsString(
            'ID_LIKE',
            $source,
            'Server::validateOS should support ID_LIKE fallback'
        );

        // Should not be commented out
        $this->assertStringNotContainsString(
            '// $ID_LIKE = data_get',
            $source,
            'ID_LIKE support should not be commented out'
        );
    }

    /**
     * Test that constants file documents supported Debian versions.
     */
    public function test_supported_os_constant_structure(): void
    {
        $this->assertIsArray(SUPPORTED_OS, 'SUPPORTED_OS should be an array');
        $this->assertNotEmpty(SUPPORTED_OS, 'SUPPORTED_OS should not be empty');

        // First entry should contain Debian-based distros
        $debianBased = SUPPORTED_OS[0];
        $this->assertStringContainsString('debian', $debianBased);
        $this->assertStringContainsString('ubuntu', $debianBased);
    }
}
