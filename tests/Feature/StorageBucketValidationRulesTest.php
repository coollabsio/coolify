<?php

use App\Livewire\Storage\Create;
use App\Livewire\Storage\Form;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidS3BucketName;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);
});

function storageRulesFor(object $component): array
{
    $method = new ReflectionMethod($component, 'rules');

    return $method->invoke($component);
}

it('uses the shared S3 bucket rule in storage create and edit forms', function (string $componentClass) {
    $component = new $componentClass;
    if ($component instanceof Form) {
        $component->storage = S3Storage::query()->create([
            'team_id' => $this->team->id,
            'name' => 'Backup storage',
            'region' => 'us-east-1',
            'key' => 'access-key',
            'secret' => 'secret-key',
            'bucket' => 'backups',
            'endpoint' => 'https://1.1.1.1',
        ]);
    }

    $bucketRules = storageRulesFor($component)['bucket'];

    expect($bucketRules)->toContain('required')
        ->and(collect($bucketRules)->contains(fn ($rule) => $rule instanceof ValidS3BucketName))->toBeTrue();
})->with([
    'create form' => [Create::class],
    'edit form' => [Form::class],
]);
