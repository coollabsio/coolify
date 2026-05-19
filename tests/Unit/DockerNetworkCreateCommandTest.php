<?php

namespace Tests\Unit;

use Tests\TestCase;

class DockerNetworkCreateCommandTest extends TestCase
{
    public function test_standalone_networks_try_ipv6_then_fall_back_to_ipv4(): void
    {
        $command = dockerNetworkCreateCommand('coolify');

        $this->assertSame(
            "(docker network create --ipv6 --attachable 'coolify' 2>/dev/null || docker network create --attachable 'coolify')",
            $command
        );
    }

    public function test_standalone_networks_can_suppress_output(): void
    {
        $command = dockerNetworkCreateCommand('coolify', suppressOutput: true);

        $this->assertSame(
            "(docker network create --ipv6 --attachable 'coolify' >/dev/null 2>/dev/null || docker network create --attachable 'coolify' >/dev/null 2>&1)",
            $command
        );
    }

    public function test_swarm_networks_keep_existing_overlay_behavior(): void
    {
        $command = dockerNetworkCreateCommand('coolify-overlay', isSwarm: true, suppressOutput: true);

        $this->assertSame(
            "docker network create --driver overlay --attachable 'coolify-overlay' >/dev/null 2>&1",
            $command
        );
    }
}
