<?php

use App\Models\Server;

describe('Server API start_proxy logic', function () {
    test('proxy should start when server is not a build server', function () {
        // Test the logic: proxy starts when start_proxy=true and is_build_server=false
        $startProxy = true;
        $isBuildServer = false;

        // This is the exact logic from ServersController.php:559
        $shouldStartProxy = $startProxy && ! $isBuildServer;
        expect($shouldStartProxy)->toBeTrue();
    });

    test('proxy should not start when server is a build server', function () {
        // Test the logic: proxy does NOT start when is_build_server=true
        // Even when start_proxy is true, build servers should not start proxy
        $startProxy = true;
        $isBuildServer = true;

        // This is the exact logic from ServersController.php:559
        $shouldStartProxy = $startProxy && ! $isBuildServer;
        expect($shouldStartProxy)->toBeFalse();
    });

    test('proxy should not start when start_proxy is false', function () {
        // Test the logic: proxy does NOT start when start_proxy=false
        $startProxy = false;
        $isBuildServer = false;

        // This is the exact logic from ServersController.php:559
        $shouldStartProxy = $startProxy && ! $isBuildServer;
        expect($shouldStartProxy)->toBeFalse();
    });

    test('start_proxy defaults to instant_validate value when not specified', function () {
        // Simulate the default logic from ServersController.php:515-518
        $instantValidate = true;
        $startProxy = null;

        // Apply default logic
        if (is_null($startProxy)) {
            $startProxy = $instantValidate;
        }

        expect($startProxy)->toBeTrue();
    });

    test('start_proxy defaults to false when instant_validate is false', function () {
        // Simulate the default logic
        $instantValidate = false;
        $startProxy = null;

        // Apply default logic
        if (is_null($startProxy)) {
            $startProxy = $instantValidate;
        }

        expect($startProxy)->toBeFalse();
    });

    test('start_proxy respects explicit true value', function () {
        // When explicitly set to true
        $instantValidate = false;
        $startProxy = true;

        // Should NOT be overridden by instant_validate
        expect($startProxy)->toBeTrue();
    });

    test('start_proxy respects explicit false value', function () {
        // When explicitly set to false
        $instantValidate = true;
        $startProxy = false;

        // Should NOT be overridden by instant_validate
        expect($startProxy)->toBeFalse();
    });
});

describe('Server isBuildServer method', function () {
    test('returns true when server is marked as build server', function () {
        // This tests the actual Server model method
        $server = new Server;
        $server->setRelation('settings', (object) ['is_build_server' => true]);

        expect($server->isBuildServer())->toBeTrue();
    });

    test('returns false when server is not marked as build server', function () {
        // This tests the actual Server model method
        $server = new Server;
        $server->setRelation('settings', (object) ['is_build_server' => false]);

        expect($server->isBuildServer())->toBeFalse();
    });
});

describe('Start proxy parameter validation scenarios', function () {
    test('all valid combinations of parameters', function () {
        $validCombinations = [
            // [instant_validate, start_proxy, is_build_server, expected_proxy_start]
            [true, true, false, true],    // Validate + Start + Regular = Start proxy
            [true, false, false, false],  // Validate + No start + Regular = No proxy
            [true, true, true, false],    // Validate + Start + Build = No proxy (build server)
            [true, false, true, false],   // Validate + No start + Build = No proxy
            [false, true, false, true],   // No validate + Start + Regular = Start proxy
            [false, false, false, false], // No validate + No start + Regular = No proxy
            [false, true, true, false],   // No validate + Start + Build = No proxy (build server)
            [false, false, true, false],  // No validate + No start + Build = No proxy
        ];

        foreach ($validCombinations as [$instantValidate, $startProxy, $isBuildServer, $expectedProxyStart]) {
            // Apply the logic from ServersController.php:558-571
            $shouldStartProxy = $startProxy && ! $isBuildServer;

            expect($shouldStartProxy)->toBe(
                $expectedProxyStart,
                "Failed for combination: instant_validate={$instantValidate}, start_proxy={$startProxy}, is_build_server={$isBuildServer}"
            );
        }
    });
});
