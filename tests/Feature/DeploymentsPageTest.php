     1|<?php
     2|
     3|use App\Enums\ApplicationDeploymentStatus;
     4|use App\Livewire\Deployments;
     5|use App\Models\Application;
     6|use App\Models\ApplicationDeploymentQueue;
     7|use App\Models\Environment;
     8|use App\Models\InstanceSettings;
     9|use App\Models\Project;
    10|use App\Models\Team;
    11|use App\Models\User;
    12|use Illuminate\Foundation\Testing\RefreshDatabase;
    13|use Livewire\Livewire;
    14|
    15|uses(RefreshDatabase::class);
    16|
    17|beforeEach(function () {
    18|    InstanceSettings::query()->forceCreate([
    19|        'id' => 0,
    20|        'is_registration_enabled' => true,
    21|    ]);
    22|
    23|    $this->team = Team::factory()->create();
    24|    $this->user = User::factory()->create();
    25|    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    26|
    27|    $this->actingAs($this->user);
    28|    session(['currentTeam' => $this->team]);
    29|});
    30|
    31|function createDeploymentForTeam(Team $team, array $deploymentOverrides = [], array $applicationOverrides = [], array $projectOverrides = []): ApplicationDeploymentQueue
    32|{
    33|    $project = Project::factory()->create(array_merge([
    34|        'team_id' => $team->id,
    35|        'name' => 'Alpha Project',
    36|    ], $projectOverrides));
    37|
    38|    $environment = Environment::factory()->create([
    39|        'project_id' => $project->id,
    40|    ]);
    41|
    42|    $application = Application::factory()->create(array_merge([
    43|        'environment_id' => $environment->id,
    44|        'name' => 'Alpha App',
    45|    ], $applicationOverrides));
    46|
    47|    return ApplicationDeploymentQueue::query()->create(array_merge([
    48|        'application_id' => (string) $application->id,
    49|        'deployment_uuid' => fake()->lexify('dep-'.$application->id.'-????????'),
    50|        'status' => 'queued',
    51|        'application_name' => $application->name,
    52|        'server_name' => 'Main Server',
    53|        'server_id' => 101,
    54|        'git_type' => 'github',
    55|        'deployment_url' => '/project/test/environment/test/application/test/deployment',
    56|        'commit_message' => 'Deploy alpha app',
    57|    ], $deploymentOverrides));
    58|}
    59|
    60|it('shows deployments page link in the main navbar', function () {
    61|    $response = $this->get('/');
    62|
    63|    $response->assertOk();
    64|    $response->assertSee('Deployments');
    65|    $response->assertSee('/deployments', false);
    66|});
    67|
    68|it('lists deployments belonging to the current team', function () {
    69|    createDeploymentForTeam($this->team);
    70|    createDeploymentForTeam($this->team, [
    71|        'status' => 'finished',
    72|        'server_name' => 'Build Server',
    73|    ], [
    74|        'name' => 'Beta App',
    75|    ], [
    76|        'name' => 'Beta Project',
    77|    ]);
    78|
    79|    $otherTeam = Team::factory()->create();
    80|    createDeploymentForTeam($otherTeam, [], ['name' => 'Hidden App'], ['name' => 'Hidden Project']);
    81|
    82|    $response = $this->get('/deployments');
    83|
    84|    $response->assertOk();
    85|    $response->assertSee('Alpha App');
    86|    $response->assertSee('Beta App');
    87|    $response->assertDontSee('Hidden App');
    88|    $response->assertSee('Alpha Project');
    89|    $response->assertSee('Beta Project');
    90|});
    91|
    92|it('filters deployments by status, project, server, and source', function () {
    93|    createDeploymentForTeam($this->team, [
    94|        'status' => 'queued',
    95|        'server_name' => 'Queue Server',
    96|        'git_type' => 'github',
    97|    ], [
    98|        'name' => 'Queued App',
    99|    ], [
   100|        'name' => 'Queued Project',
   101|    ]);
   102|
   103|    createDeploymentForTeam($this->team, [
   104|        'status' => 'failed',
   105|        'server_name' => 'Fail Server',
   106|        'git_type' => 'gitlab',
   107|    ], [
   108|        'name' => 'Failed App',
   109|    ], [
   110|        'name' => 'Failed Project',
   111|    ]);
   112|
   113|    $response = $this->get('/deployments?status=queued&project=Queued+Project&server=Queue+Server&source=github');
   114|
   115|    $response->assertOk();
   116|    $response->assertSee('Queued App');
   117|    $response->assertDontSee('Failed App');
   118|
   119|    Livewire::test(Deployments::class)
   120|        ->set('status', 'queued')
   121|        ->set('project', 'Queued Project')
   122|        ->set('server', 'Queue Server')
   123|        ->set('source', 'github')
   124|        ->assertSee('Queued App')
   125|        ->assertDontSee('Failed App');
   126|});
   127|
   128|it('hides server and source filters when there is only one choice', function () {
   129|    createDeploymentForTeam($this->team, [
   130|        'status' => 'queued',
   131|        'server_name' => 'Solo Server',
   132|        'git_type' => 'github',
   133|    ], [
   134|        'name' => 'Solo App',
   135|    ], [
   136|        'name' => 'Solo Project',
   137|    ]);
   138|
   139|    $response = $this->get('/deployments');
   140|
   141|    $response->assertOk();
   142|    $response->assertSee('All projects');
   143|    $response->assertSee('All statuses');
   144|    $response->assertDontSee('All servers');
   145|    $response->assertDontSee('All sources');
   146|});
   147|
   148|it('keeps all project options available while other filters are active', function () {
   149|    createDeploymentForTeam($this->team, [
   150|        'status' => ApplicationDeploymentStatus::QUEUED->value,
   151|        'server_name' => 'Queue Server',
   152|        'git_type' => 'github',
   153|    ], [
   154|        'name' => 'Queued App',
   155|    ], [
   156|        'name' => 'Queued Project',
   157|    ]);
   158|
   159|    createDeploymentForTeam($this->team, [
   160|        'status' => ApplicationDeploymentStatus::FAILED->value,
   161|        'server_name' => 'Fail Server',
   162|        'git_type' => 'gitlab',
   163|    ], [
   164|        'name' => 'Failed App',
   165|    ], [
   166|        'name' => 'Failed Project',
   167|    ]);
   168|
   169|    $response = $this->get('/deployments?status='.ApplicationDeploymentStatus::QUEUED->value);
   170|
   171|    $response->assertOk();
   172|    $response->assertSee('Queued App');
   173|    $response->assertDontSee('Failed App');
   174|    $response->assertSee('Queued Project');
   175|    $response->assertSee('Failed Project');
   176|});
   177|