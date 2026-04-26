<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

it('persists and decrypts a 64-char http basic auth password', function () {
    $longPassword = str_repeat('a', 64);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'http_basic_auth_password' => $longPassword,
    ]);

    $raw = DB::table('applications')
        ->where('id', $application->id)
        ->value('http_basic_auth_password');

    expect(strlen($raw))->toBeGreaterThan(255);

    $application->refresh();
    expect($application->http_basic_auth_password)->toBe($longPassword);
});
