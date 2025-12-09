<?php

use App\Services\ContainerStatusAggregator;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->aggregator = new ContainerStatusAggregator;
});

describe('aggregateFromStrings', function () {
    test('returns exited for empty collection', function () {
        $result = $this->aggregator->aggregateFromStrings(collect());

        expect($result)->toBe('exited');
    });

    test('returns running:healthy for single healthy running container', function () {
        $statuses = collect(['running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:healthy');
    });

    test('returns running:unhealthy for single unhealthy running container', function () {
        $statuses = collect(['running:unhealthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unhealthy');
    });

    test('returns running:unknown for single running container with unknown health', function () {
        $statuses = collect(['running:unknown']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unknown');
    });

    test('returns degraded:unhealthy for restarting container', function () {
        $statuses = collect(['restarting']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy for mixed running and exited containers', function () {
        $statuses = collect(['running:healthy', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns running:unhealthy when one of multiple running containers is unhealthy', function () {
        $statuses = collect(['running:healthy', 'running:unhealthy', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unhealthy');
    });

    test('returns running:unknown when running containers have unknown health', function () {
        $statuses = collect(['running:unknown', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unknown');
    });

    test('returns degraded:unhealthy for crash loop (exited with restart count)', function () {
        $statuses = collect(['exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 5);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns exited for exited containers without restart count', function () {
        $statuses = collect(['exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 0);

        expect($result)->toBe('exited');
    });

    test('returns degraded:unhealthy for dead container', function () {
        $statuses = collect(['dead']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy for removing container', function () {
        $statuses = collect(['removing']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns paused:unknown for paused container', function () {
        $statuses = collect(['paused']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('paused:unknown');
    });

    test('returns starting:unknown for starting container', function () {
        $statuses = collect(['starting']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('returns starting:unknown for created container', function () {
        $statuses = collect(['created']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('returns degraded:unhealthy for single degraded container', function () {
        $statuses = collect(['degraded:unhealthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy when mixing degraded with running healthy', function () {
        $statuses = collect(['degraded:unhealthy', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy when mixing running healthy with degraded', function () {
        $statuses = collect(['running:healthy', 'degraded:unhealthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy for multiple degraded containers', function () {
        $statuses = collect(['degraded:unhealthy', 'degraded:unhealthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('degraded status overrides all other non-critical states', function () {
        $statuses = collect(['degraded:unhealthy', 'running:healthy', 'starting', 'paused']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns starting:unknown when mixing starting with running healthy (service not fully ready)', function () {
        $statuses = collect(['starting:unknown', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('returns starting:unknown when mixing created with running healthy', function () {
        $statuses = collect(['created', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('returns starting:unknown for multiple starting containers with some running', function () {
        $statuses = collect(['starting:unknown', 'starting:unknown', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('handles parentheses format input (backward compatibility)', function () {
        $statuses = collect(['running (healthy)', 'running (unhealthy)']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unhealthy');
    });

    test('handles mixed colon and parentheses formats', function () {
        $statuses = collect(['running:healthy', 'running (unhealthy)', 'running:healthy']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unhealthy');
    });

    test('prioritizes restarting over all other states', function () {
        $statuses = collect(['restarting', 'running:healthy', 'paused', 'starting']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('prioritizes crash loop over running containers', function () {
        $statuses = collect(['exited', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 3);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('prioritizes mixed state over healthy running', function () {
        $statuses = collect(['running:healthy', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 0);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('mixed running and starting returns starting', function () {
        $statuses = collect(['running:healthy', 'starting']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('prioritizes running over paused/exited when no starting', function () {
        $statuses = collect(['running:healthy', 'paused', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:healthy');
    });

    test('prioritizes dead over paused/starting/exited', function () {
        $statuses = collect(['dead', 'paused', 'starting']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('prioritizes paused over starting/exited', function () {
        $statuses = collect(['paused', 'starting', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('paused:unknown');
    });

    test('prioritizes starting over exited', function () {
        $statuses = collect(['starting', 'exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });
});

describe('aggregateFromContainers', function () {
    test('returns exited for empty collection', function () {
        $result = $this->aggregator->aggregateFromContainers(collect());

        expect($result)->toBe('exited');
    });

    test('returns running:healthy for single healthy running container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'healthy'],
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('running:healthy');
    });

    test('returns running:unhealthy for single unhealthy running container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'unhealthy'],
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('running:unhealthy');
    });

    test('returns running:unknown for running container without health check', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => null,
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('running:unknown');
    });

    test('returns degraded:unhealthy for restarting container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'restarting',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy for mixed running and exited containers', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'healthy'],
                ],
            ],
            (object) [
                'State' => (object) [
                    'Status' => 'exited',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns degraded:unhealthy for crash loop (exited with restart count)', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'exited',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers, maxRestartCount: 5);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns exited for exited containers without restart count', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'exited',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers, maxRestartCount: 0);

        expect($result)->toBe('exited');
    });

    test('returns degraded:unhealthy for dead container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'dead',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('returns paused:unknown for paused container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'paused',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('paused:unknown');
    });

    test('returns starting:unknown for starting container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'starting',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('starting:unknown');
    });

    test('returns starting:unknown for created container', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'created',
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('starting:unknown');
    });

    test('handles multiple containers with various states', function () {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'healthy'],
                ],
            ],
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'unhealthy'],
                ],
            ],
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => null,
                ],
            ],
        ]);

        $result = $this->aggregator->aggregateFromContainers($containers);

        expect($result)->toBe('running:unhealthy');
    });
});

describe('state priority enforcement', function () {
    test('degraded from sub-resources has highest priority', function () {
        $statuses = collect([
            'degraded:unhealthy',
            'restarting',
            'running:healthy',
            'dead',
            'paused',
            'starting',
            'exited',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('restarting has second highest priority', function () {
        $statuses = collect([
            'restarting',
            'running:healthy',
            'dead',
            'paused',
            'starting',
            'exited',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('crash loop has third highest priority', function () {
        $statuses = collect([
            'exited',
            'running:healthy',
            'paused',
            'starting',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 1);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('mixed state (running + exited) has fourth priority', function () {
        $statuses = collect([
            'running:healthy',
            'exited',
            'paused',
            'starting',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 0);

        expect($result)->toBe('degraded:unhealthy');
    });

    test('mixed state (running + starting) has fifth priority', function () {
        $statuses = collect([
            'running:healthy',
            'starting',
            'paused',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('starting:unknown');
    });

    test('running:unhealthy has priority over running:unknown', function () {
        $statuses = collect([
            'running:unknown',
            'running:unhealthy',
            'running:healthy',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unhealthy');
    });

    test('running:unknown has priority over running:healthy', function () {
        $statuses = collect([
            'running:unknown',
            'running:healthy',
        ]);

        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('running:unknown');
    });
});

describe('maxRestartCount validation', function () {
    test('negative maxRestartCount is corrected to 0 in aggregateFromStrings', function () {
        // Mock the Log facade to avoid "facade root not set" error in unit tests
        Log::shouldReceive('warning')->once();

        $statuses = collect(['exited']);

        // With negative value, should be treated as 0 (no restarts)
        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: -5);

        // Should return exited (not degraded) since corrected to 0
        expect($result)->toBe('exited');
    });

    test('negative maxRestartCount is corrected to 0 in aggregateFromContainers', function () {
        // Mock the Log facade to avoid "facade root not set" error in unit tests
        Log::shouldReceive('warning')->once();

        $containers = collect([
            [
                'State' => [
                    'Status' => 'exited',
                    'ExitCode' => 1,
                ],
            ],
        ]);

        // With negative value, should be treated as 0 (no restarts)
        $result = $this->aggregator->aggregateFromContainers($containers, maxRestartCount: -10);

        // Should return exited (not degraded) since corrected to 0
        expect($result)->toBe('exited');
    });

    test('zero maxRestartCount works correctly', function () {
        $statuses = collect(['exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 0);

        // Zero is valid default - no crash loop detection
        expect($result)->toBe('exited');
    });

    test('positive maxRestartCount works correctly', function () {
        $statuses = collect(['exited']);

        $result = $this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 5);

        // Positive value enables crash loop detection
        expect($result)->toBe('degraded:unhealthy');
    });

    test('crash loop detection still functions after validation', function () {
        $statuses = collect(['exited']);

        // Test with various positive restart counts
        expect($this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 1))
            ->toBe('degraded:unhealthy');

        expect($this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 100))
            ->toBe('degraded:unhealthy');

        expect($this->aggregator->aggregateFromStrings($statuses, maxRestartCount: 999))
            ->toBe('degraded:unhealthy');
    });

    test('default maxRestartCount parameter works', function () {
        $statuses = collect(['exited']);

        // Call without specifying maxRestartCount (should default to 0)
        $result = $this->aggregator->aggregateFromStrings($statuses);

        expect($result)->toBe('exited');
    });
});
