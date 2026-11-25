<?php

describe('formatContainerStatus helper', function () {
    describe('colon-delimited format parsing', function () {
        it('transforms running:healthy to Running (healthy)', function () {
            $result = formatContainerStatus('running:healthy');

            expect($result)->toBe('Running (healthy)');
        });

        it('transforms running:unhealthy to Running (unhealthy)', function () {
            $result = formatContainerStatus('running:unhealthy');

            expect($result)->toBe('Running (unhealthy)');
        });

        it('transforms exited:0 to Exited (0)', function () {
            $result = formatContainerStatus('exited:0');

            expect($result)->toBe('Exited (0)');
        });

        it('transforms restarting:starting to Restarting (starting)', function () {
            $result = formatContainerStatus('restarting:starting');

            expect($result)->toBe('Restarting (starting)');
        });
    });

    describe('excluded suffix handling', function () {
        it('transforms running:unhealthy:excluded to Running (unhealthy, excluded)', function () {
            $result = formatContainerStatus('running:unhealthy:excluded');

            expect($result)->toBe('Running (unhealthy, excluded)');
        });

        it('transforms running:healthy:excluded to Running (healthy, excluded)', function () {
            $result = formatContainerStatus('running:healthy:excluded');

            expect($result)->toBe('Running (healthy, excluded)');
        });

        it('transforms exited:excluded to Exited (excluded)', function () {
            $result = formatContainerStatus('exited:excluded');

            expect($result)->toBe('Exited (excluded)');
        });

        it('transforms stopped:excluded to Stopped (excluded)', function () {
            $result = formatContainerStatus('stopped:excluded');

            expect($result)->toBe('Stopped (excluded)');
        });
    });

    describe('simple status format', function () {
        it('transforms running to Running', function () {
            $result = formatContainerStatus('running');

            expect($result)->toBe('Running');
        });

        it('transforms exited to Exited', function () {
            $result = formatContainerStatus('exited');

            expect($result)->toBe('Exited');
        });

        it('transforms stopped to Stopped', function () {
            $result = formatContainerStatus('stopped');

            expect($result)->toBe('Stopped');
        });

        it('transforms restarting to Restarting', function () {
            $result = formatContainerStatus('restarting');

            expect($result)->toBe('Restarting');
        });

        it('transforms degraded to Degraded', function () {
            $result = formatContainerStatus('degraded');

            expect($result)->toBe('Degraded');
        });
    });

    describe('Proxy status preservation', function () {
        it('preserves Proxy:running without parsing colons', function () {
            $result = formatContainerStatus('Proxy:running');

            expect($result)->toBe('Proxy:running');
        });

        it('preserves Proxy:exited without parsing colons', function () {
            $result = formatContainerStatus('Proxy:exited');

            expect($result)->toBe('Proxy:exited');
        });

        it('preserves Proxy:healthy without parsing colons', function () {
            $result = formatContainerStatus('Proxy:healthy');

            expect($result)->toBe('Proxy:healthy');
        });

        it('applies headline formatting to Proxy statuses', function () {
            $result = formatContainerStatus('proxy:running');

            expect($result)->toBe('Proxy (running)');
        });
    });

    describe('headline transformation', function () {
        it('applies headline to simple lowercase status', function () {
            $result = formatContainerStatus('running');

            expect($result)->toBe('Running');
        });

        it('applies headline to uppercase status', function () {
            // headline() adds spaces between capital letters
            $result = formatContainerStatus('RUNNING');

            expect($result)->toBe('R U N N I N G');
        });

        it('applies headline to mixed case status', function () {
            // headline() adds spaces between capital letters
            $result = formatContainerStatus('RuNnInG');

            expect($result)->toBe('Ru Nn In G');
        });

        it('applies headline to first part of colon format', function () {
            // headline() adds spaces between capital letters
            $result = formatContainerStatus('RUNNING:healthy');

            expect($result)->toBe('R U N N I N G (healthy)');
        });
    });

    describe('edge cases', function () {
        it('handles empty string gracefully', function () {
            $result = formatContainerStatus('');

            expect($result)->toBe('');
        });

        it('handles multiple colons beyond expected format', function () {
            // Only first two parts should be used (or three with :excluded)
            $result = formatContainerStatus('running:healthy:extra:data');

            expect($result)->toBe('Running (healthy)');
        });

        it('handles status with spaces in health part', function () {
            $result = formatContainerStatus('running:health check failed');

            expect($result)->toBe('Running (health check failed)');
        });

        it('handles single colon with empty second part', function () {
            $result = formatContainerStatus('running:');

            expect($result)->toBe('Running ()');
        });
    });

    describe('real-world scenarios', function () {
        it('handles typical running healthy container', function () {
            $result = formatContainerStatus('running:healthy');

            expect($result)->toBe('Running (healthy)');
        });

        it('handles degraded container with health issues', function () {
            $result = formatContainerStatus('degraded:unhealthy');

            expect($result)->toBe('Degraded (unhealthy)');
        });

        it('handles excluded unhealthy container', function () {
            $result = formatContainerStatus('running:unhealthy:excluded');

            expect($result)->toBe('Running (unhealthy, excluded)');
        });

        it('handles proxy container status', function () {
            $result = formatContainerStatus('Proxy:running');

            expect($result)->toBe('Proxy:running');
        });

        it('handles stopped container', function () {
            $result = formatContainerStatus('stopped');

            expect($result)->toBe('Stopped');
        });
    });
});
