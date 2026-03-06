<?php

use App\Jobs\ApplicationDeploymentJob;

describe('deployment job path field validation', function () {
    test('rejects shell metacharacters in dockerfile_location', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/Dockerfile; echo pwned', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });

    test('rejects backtick injection', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/Dockerfile`whoami`', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });

    test('rejects dollar sign variable expansion', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/Dockerfile$(whoami)', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });

    test('rejects pipe injection', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/Dockerfile | cat /etc/passwd', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });

    test('rejects ampersand injection', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/Dockerfile && env', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });

    test('rejects path traversal', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/../../../etc/passwd', 'dockerfile_location'))
            ->toThrow(RuntimeException::class, 'path traversal detected');
    });

    test('allows valid simple path', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect($method->invoke($instance, '/Dockerfile', 'dockerfile_location'))
            ->toBe('/Dockerfile');
    });

    test('allows valid nested path with dots and hyphens', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect($method->invoke($instance, '/docker/Dockerfile.prod', 'dockerfile_location'))
            ->toBe('/docker/Dockerfile.prod');
    });

    test('allows valid compose file path', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect($method->invoke($instance, '/docker-compose.prod.yml', 'docker_compose_location'))
            ->toBe('/docker-compose.prod.yml');
    });
});

describe('API validation rules for path fields', function () {
    test('dockerfile_location validation rejects shell metacharacters', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_location' => '/Dockerfile; echo pwned; #'],
            ['dockerfile_location' => $rules['dockerfile_location']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('dockerfile_location validation allows valid paths', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_location' => '/docker/Dockerfile.prod'],
            ['dockerfile_location' => $rules['dockerfile_location']]
        );

        expect($validator->fails())->toBeFalse();
    });

    test('docker_compose_location validation rejects shell metacharacters', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_location' => '/docker-compose.yml; env; #'],
            ['docker_compose_location' => $rules['docker_compose_location']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('docker_compose_location validation allows valid paths', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_location' => '/docker/docker-compose.prod.yml'],
            ['docker_compose_location' => $rules['docker_compose_location']]
        );

        expect($validator->fails())->toBeFalse();
    });
});

describe('sharedDataApplications rules survive array_merge in controller', function () {
    test('docker_compose_location safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        // Simulate what ApplicationsController does: array_merge(shared, local)
        // After our fix, local no longer contains docker_compose_location,
        // so the shared regex rule must survive
        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        // The merged rules for docker_compose_location should be the safe regex, not just 'string'
        expect($merged['docker_compose_location'])->toBeArray();
        expect($merged['docker_compose_location'])->toContain('regex:/^\/[a-zA-Z0-9._\-\/]+$/');
    });
});

describe('path fields require leading slash', function () {
    test('dockerfile_location without leading slash is rejected by API rules', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_location' => 'Dockerfile'],
            ['dockerfile_location' => $rules['dockerfile_location']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('docker_compose_location without leading slash is rejected by API rules', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_location' => 'docker-compose.yaml'],
            ['docker_compose_location' => $rules['docker_compose_location']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('deployment job rejects path without leading slash', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, 'docker-compose.yaml', 'docker_compose_location'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');
    });
});

describe('API route middleware for deploy actions', function () {
    test('application start route requires deploy ability', function () {
        $routes = app('router')->getRoutes();
        $route = $routes->getByAction('App\Http\Controllers\Api\ApplicationsController@action_deploy');

        expect($route)->not->toBeNull();
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('api.ability:deploy');
        expect($middleware)->not->toContain('api.ability:write');
    });

    test('application restart route requires deploy ability', function () {
        $routes = app('router')->getRoutes();
        $matchedRoute = null;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'applications') && str_contains($route->uri(), 'restart')) {
                $matchedRoute = $route;
                break;
            }
        }

        expect($matchedRoute)->not->toBeNull();
        $middleware = $matchedRoute->gatherMiddleware();
        expect($middleware)->toContain('api.ability:deploy');
    });

    test('application stop route requires deploy ability', function () {
        $routes = app('router')->getRoutes();
        $matchedRoute = null;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'applications') && str_contains($route->uri(), 'stop')) {
                $matchedRoute = $route;
                break;
            }
        }

        expect($matchedRoute)->not->toBeNull();
        $middleware = $matchedRoute->gatherMiddleware();
        expect($middleware)->toContain('api.ability:deploy');
    });

    test('database start route requires deploy ability', function () {
        $routes = app('router')->getRoutes();
        $matchedRoute = null;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'databases') && str_contains($route->uri(), 'start')) {
                $matchedRoute = $route;
                break;
            }
        }

        expect($matchedRoute)->not->toBeNull();
        $middleware = $matchedRoute->gatherMiddleware();
        expect($middleware)->toContain('api.ability:deploy');
    });

    test('service start route requires deploy ability', function () {
        $routes = app('router')->getRoutes();
        $matchedRoute = null;
        foreach ($routes as $route) {
            if (str_contains($route->uri(), 'services') && str_contains($route->uri(), 'start')) {
                $matchedRoute = $route;
                break;
            }
        }

        expect($matchedRoute)->not->toBeNull();
        $middleware = $matchedRoute->gatherMiddleware();
        expect($middleware)->toContain('api.ability:deploy');
    });
});
