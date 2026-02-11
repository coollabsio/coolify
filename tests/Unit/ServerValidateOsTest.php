<?php

use App\Models\Server;
use Tests\TestCase;

class ServerValidateOsTest extends TestCase
{
    /** @test */
    public function it_resolves_debian_based_os_release(): void
    {
        $server = new Server();
        $method = new ReflectionMethod(Server::class, 'resolveSupportedOsType');
        $method->setAccessible(true);

        $osRelease = <<<TXT
ID=debian
VERSION_ID="13"
VERSION_CODENAME=trixie
TXT;

        $result = $method->invoke($server, $osRelease);

        $this->assertNotNull($result);
        $this->assertTrue(str($result)->contains('debian'));
    }

    /** @test */
    public function it_uses_id_like_for_supported_os_resolution(): void
    {
        $server = new Server();
        $method = new ReflectionMethod(Server::class, 'resolveSupportedOsType');
        $method->setAccessible(true);

        $osRelease = <<<TXT
ID=linuxmint
ID_LIKE="ubuntu debian"
VERSION_ID="22"
TXT;

        $result = $method->invoke($server, $osRelease);

        $this->assertNotNull($result);
        $this->assertTrue(str($result)->contains('debian'));
    }

    /** @test */
    public function it_returns_null_for_unsupported_os_release(): void
    {
        $server = new Server();
        $method = new ReflectionMethod(Server::class, 'resolveSupportedOsType');
        $method->setAccessible(true);

        $osRelease = <<<TXT
ID=gentoo
VERSION_ID="2"
TXT;

        $result = $method->invoke($server, $osRelease);

        $this->assertNull($result);
    }
}
