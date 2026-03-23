<?php

use App\Livewire\Project\Shared\FileExplorer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('File Explorer Component', function () {
    test('component class exists and can be instantiated', function () {
        expect(class_exists(FileExplorer::class))->toBeTrue();
    });

    test('routes are registered correctly', function () {
        // Verify routes exist
        expect(route('project.application.files', [
            'project_uuid' => 'test',
            'environment_uuid' => 'test',
            'application_uuid' => 'test',
        ]))->toBeString();

        expect(route('project.database.files', [
            'project_uuid' => 'test',
            'environment_uuid' => 'test',
            'database_uuid' => 'test',
        ]))->toBeString();

        expect(route('project.service.files', [
            'project_uuid' => 'test',
            'environment_uuid' => 'test',
            'service_uuid' => 'test',
        ]))->toBeString();

        expect(route('project.file.download', [
            'token' => 'test-token',
        ]))->toBeString();
    });

    test('component has required properties', function () {
        $component = new FileExplorer;

        expect($component)->toHaveProperty('selected_container');
        expect($component)->toHaveProperty('containers');
        expect($component)->toHaveProperty('currentPath');
        expect($component)->toHaveProperty('files');
        expect($component)->toHaveProperty('isLoading');
    });

    test('component has required methods', function () {
        $component = new FileExplorer;

        expect(method_exists($component, 'mount'))->toBeTrue();
        expect(method_exists($component, 'loadContainers'))->toBeTrue();
        expect(method_exists($component, 'loadFiles'))->toBeTrue();
        expect(method_exists($component, 'openFile'))->toBeTrue();
        expect(method_exists($component, 'saveFile'))->toBeTrue();
        expect(method_exists($component, 'createFolder'))->toBeTrue();
        expect(method_exists($component, 'deleteFile'))->toBeTrue();
        expect(method_exists($component, 'deleteFileByEncodedPath'))->toBeTrue();
        expect(method_exists($component, 'deleteSelectedFiles'))->toBeTrue();
        expect(method_exists($component, 'getDownloadUrl'))->toBeTrue();
    });

    test('parseFileList handles empty output', function () {
        $component = new FileExplorer;
        $reflection = new ReflectionClass($component);
        $method = $reflection->getMethod('parseFileList');
        $method->setAccessible(true);

        $result = $method->invoke($component, '');
        expect($result)->toBeArray();
        expect($result)->toBeEmpty();
    });

    test('formatSize formats bytes correctly', function () {
        $component = new FileExplorer;
        $reflection = new ReflectionClass($component);
        $method = $reflection->getMethod('formatSize');
        $method->setAccessible(true);

        expect($method->invoke($component, 0))->toBe('0 B');
        expect($method->invoke($component, 1024))->toBe('1 KB');
        expect($method->invoke($component, 1048576))->toBe('1 MB');
    });
});
