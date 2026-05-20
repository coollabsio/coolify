<?php

use App\Models\Application;

describe('Application compose file loading', function () {
    test('redirects git setup output away from compose file content', function () {
        $application = new Application;
        $method = new ReflectionMethod(Application::class, 'composeFileLoadSetupCommand');
        $method->setAccessible(true);

        $command = $method->invoke($application, 'git clone --no-checkout repo .', '/tmp/coolify-test/compose-load.log');

        expect($command)
            ->toBe("git clone --no-checkout repo . >> '/tmp/coolify-test/compose-load.log' 2>&1 || { cat '/tmp/coolify-test/compose-load.log' >&2; exit 1; }")
            ->toContain("cat '/tmp/coolify-test/compose-load.log' >&2");
    });
});
