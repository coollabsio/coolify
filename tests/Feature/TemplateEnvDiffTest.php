<?php

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Services\TemplateEnvDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeServiceWithEnvs(array $envs): Service
{
    $service = Service::factory()->create(['environment_id' => test()->environment->id]);
    foreach ($envs as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_id' => $service->id,
            'resourceable_type' => $service->getMorphClass(),
            'is_preview' => false,
        ]);
    }

    return $service->refresh();
}

function templateWithEnvs(string $envs): array
{
    return ['envs' => base64_encode($envs)];
}

it('categorises new, changed, and removed env keys and skips SERVICE_ keys', function () {
    $service = makeServiceWithEnvs([
        'EXISTING' => 'old',
        'GONE' => 'x',
        'SERVICE_PASSWORD_DB' => 'generated',
    ]);
    $template = templateWithEnvs("EXISTING=new\nBRAND_NEW=hello\nSERVICE_PASSWORD_DB=\$SERVICE_PASSWORD");

    $diff = TemplateEnvDiff::compute($template, $service);

    expect(collect($diff['new'])->pluck('key'))->toContain('BRAND_NEW');
    expect(collect($diff['changed'])->pluck('key'))->toContain('EXISTING');
    expect(collect($diff['removed'])->pluck('key'))->toContain('GONE');

    $allKeys = collect($diff)->flatten(1)->pluck('key');
    expect($allKeys)->not->toContain('SERVICE_PASSWORD_DB');
});
