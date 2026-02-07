<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneSurrealdb;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user and team
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    
    // Create project with unique name
    $this->project = Project::create([
        'name' => 'Test Project '.uniqid(),
        'team_id' => $this->team->id,
        'uuid' => \Illuminate\Support\Str::uuid(),
    ]);
    
    // Create environment with unique name
    $this->environment = Environment::create([
        'name' => 'production-'.uniqid(),
        'project_id' => $this->project->id,
        'uuid' => \Illuminate\Support\Str::uuid(),
    ]);
    
    // Authenticate
    $this->actingAs($this->user);
});

describe('SurrealDB Model', function () {
    test('can create surrealdb database', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'name' => 'test-surrealdb',
            'environment_id' => $this->environment->id,
            'surrealdb_user' => 'root',
            'surrealdb_password' => 'testpassword',
        ]);

        expect($database)->toBeInstanceOf(StandaloneSurrealdb::class)
            ->and($database->name)->toBe('test-surrealdb')
            ->and($database->surrealdb_user)->toBe('root')
            ->and($database->type())->toBe('standalone-surrealdb');
    });

    test('has correct database type', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database->type())->toBe('standalone-surrealdb')
            ->and($database->database_type)->toBe('standalone-surrealdb');
    });

    test('has encrypted password', function () {
        $password = 'my-secret-password';
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'surrealdb_password' => $password,
        ]);

        // Password should be encrypted in database
        $rawPassword = $database->getAttributes()['surrealdb_password'];
        expect($rawPassword)->not->toBe($password);

        // But accessible normally
        expect($database->surrealdb_password)->toBe($password);
    });

    test('generates correct internal db url', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'uuid' => 'test-uuid-123',
            'surrealdb_user' => 'root',
            'surrealdb_password' => 'testpass',
            'enable_ssl' => false,
        ]);

        $url = $database->internal_db_url;
        
        expect($url)->toContain('http://')
            ->and($url)->toContain('root')
            ->and($url)->toContain('testpass')
            ->and($url)->toContain('test-uuid-123')
            ->and($url)->toContain(':8000');
    });

    test('generates correct external db url when public', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'is_public' => true,
            'public_port' => 8001,
            'surrealdb_user' => 'root',
            'surrealdb_password' => 'testpass',
        ]);

        $url = $database->external_db_url;
        
        expect($url)->not->toBeNull()
            ->and($url)->toContain(':8001');
    });

    test('returns null external url when not public', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'is_public' => false,
        ]);

        expect($database->external_db_url)->toBeNull();
    });

    test('has backup solution available', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database->isBackupSolutionAvailable())->toBeTrue();
    });

    test('creates persistent volume on creation', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $volumes = $database->persistentStorages;
        
        expect($volumes)->toHaveCount(1)
            ->and($volumes->first()->name)->toContain('surrealdb-data')
            ->and($volumes->first()->mount_path)->toBe('/data');
    });

    test('belongs to environment', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database->environment)->toBeInstanceOf(Environment::class)
            ->and($database->environment->id)->toBe($this->environment->id);
    });

    test('belongs to team through environment', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database->team())->toBeInstanceOf(Team::class)
            ->and($database->team()->id)->toBe($this->team->id);
    });

    test('has correct link', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $link = $database->link();
        
        expect($link)->not->toBeNull()
            ->and($link)->toContain('/project/')
            ->and($link)->toContain('/database/')
            ->and($link)->toContain($database->uuid);
    });

    test('can detect configuration changes', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'image' => 'surrealdb/surrealdb:v2',
        ]);

        // First check should return true (no hash set)
        expect($database->isConfigurationChanged())->toBeTrue();

        // Save the hash
        $database->isConfigurationChanged(save: true);
        $database->refresh();
        $oldHash = $database->config_hash;

        // No change should return false
        $database->refresh();
        expect($database->isConfigurationChanged())->toBeFalse();

        // Change image
        $database->image = 'surrealdb/surrealdb:v2.1';
        $database->save();
        $database->refresh();
        expect($database->isConfigurationChanged())->toBeTrue();
    });

    test('can check if running', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'status' => 'running:healthy',
        ]);

        expect($database->isRunning())->toBeTrue();

        $database->status = 'exited';
        expect($database->isRunning())->toBeFalse();
    });

    test('can check if exited', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'status' => 'exited',
        ]);

        expect($database->isExited())->toBeTrue();

        $database->status = 'running:healthy';
        expect($database->isExited())->toBeFalse();
    });

    test('status accessor formats correctly', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        // Test with parentheses format
        $database->status = 'running (healthy)';
        expect($database->status)->toBe('running:healthy');

        // Test with colon format
        $database->status = 'running:healthy';
        expect($database->status)->toBe('running:healthy');

        // Test without health status
        $database->status = 'exited';
        expect($database->status)->toBe('exited:unhealthy');
    });

    test('can be soft deleted', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $id = $database->id;
        $database->delete();

        expect(StandaloneSurrealdb::find($id))->toBeNull()
            ->and(StandaloneSurrealdb::withTrashed()->find($id))->not->toBeNull();
    });

    test('deletes related resources on force delete', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $volumeId = $database->persistentStorages->first()->id;

        $database->forceDelete();

        expect(StandaloneSurrealdb::withTrashed()->find($database->id))->toBeNull()
            ->and(\App\Models\LocalPersistentVolume::find($volumeId))->toBeNull();
    });
});

describe('SurrealDB Scopes', function () {
    test('ownedByCurrentTeam returns only team databases', function () {
        // Set current team in session
        session(['currentTeam' => $this->team]);
        
        // Create database for current team
        $ownDatabase = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        // Create another team and database with unique names
        $otherTeam = Team::factory()->create();
        $otherProject = Project::create([
            'name' => 'Other Project '.uniqid(),
            'team_id' => $otherTeam->id,
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $otherEnvironment = Environment::create([
            'name' => 'production-'.uniqid(),
            'project_id' => $otherProject->id,
            'uuid' => \Illuminate\Support\Str::uuid(),
        ]);
        $otherDatabase = StandaloneSurrealdb::factory()->create([
            'environment_id' => $otherEnvironment->id,
        ]);

        $databases = StandaloneSurrealdb::ownedByCurrentTeam()->get();

        expect($databases)->toHaveCount(1)
            ->and($databases->first()->id)->toBe($ownDatabase->id);
    });
});

describe('SurrealDB Table Structure', function () {
    test('standalone_surrealdbs table exists', function () {
        expect(\Illuminate\Support\Facades\Schema::hasTable('standalone_surrealdbs'))->toBeTrue();
    });

    test('table has required columns', function () {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('standalone_surrealdbs');

        $requiredColumns = [
            'id',
            'uuid',
            'name',
            'description',
            'image',
            'surrealdb_user',
            'surrealdb_password',
            'ports_mappings',
            'is_public',
            'public_port',
            'enable_ssl',
            'ssl_mode',
            'is_log_drain_enabled',
            'environment_id',
            'destination_type',
            'destination_id',
            'status',
            'config_hash',
            'custom_docker_run_options',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        foreach ($requiredColumns as $column) {
            expect($columns)->toContain($column);
        }
    });

    test('surrealdb_password column is encrypted', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
            'surrealdb_password' => 'plaintext',
        ]);

        $casts = $database->getCasts();
        expect($casts)->toHaveKey('surrealdb_password')
            ->and($casts['surrealdb_password'])->toBe('encrypted');
    });
});

describe('SurrealDB Relationships', function () {
    test('has environment variables relationship', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $database->environment_variables()->create([
            'key' => 'TEST_VAR',
            'value' => 'test_value',
            'is_preview' => false,
        ]);

        expect($database->environment_variables)->toHaveCount(1)
            ->and($database->environment_variables->first()->key)->toBe('TEST_VAR');
    });

    test('has persistent storages relationship', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database->persistentStorages)->toHaveCount(1);
    });

    test('has file storages relationship', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $database->fileStorages()->create([
            'fs_path' => '/test/path',
            'mount_path' => '/app/test',
            'is_directory' => true,
        ]);

        expect($database->fileStorages)->toHaveCount(1);
    });

    test('has scheduled backups relationship', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $database->scheduledBackups()->create([
            'enabled' => true,
            'frequency' => 'daily',
            'save_s3' => false,
            'team_id' => $this->team->id,
            'database_type' => StandaloneSurrealdb::class,
        ]);

        expect($database->scheduledBackups)->toHaveCount(1);
    });

    test('has tags relationship', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        $tag = \App\Models\Tag::create([
            'name' => 'production',
            'team_id' => $this->team->id,
        ]);

        $database->tags()->attach($tag);

        expect($database->tags)->toHaveCount(1)
            ->and($database->tags->first()->name)->toBe('production');
    });
});

describe('SurrealDB Factory', function () {
    test('factory creates valid database', function () {
        $database = StandaloneSurrealdb::factory()->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($database)->toBeInstanceOf(StandaloneSurrealdb::class)
            ->and($database->uuid)->not->toBeNull()
            ->and($database->name)->not->toBeNull()
            ->and($database->image)->toContain('surrealdb')
            ->and($database->surrealdb_user)->not->toBeNull()
            ->and($database->surrealdb_password)->not->toBeNull();
    });

    test('factory can create multiple databases', function () {
        $databases = StandaloneSurrealdb::factory()->count(3)->create([
            'environment_id' => $this->environment->id,
        ]);

        expect($databases)->toHaveCount(3);
        
        $uuids = $databases->pluck('uuid')->toArray();
        expect(count($uuids))->toBe(count(array_unique($uuids)));
    });
});

