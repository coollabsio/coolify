<?php

use App\Livewire\Notifications\Email as NotificationEmail;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const DEPLOYMENT_EMAIL_COMMIT_SHA = '3f9c2a7b1d4e5f60718293a4b5c6d7e8f9012345';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'commit-details-'.fake()->unique()->word(),
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'https://github.com/acme/shop.git',
        'git_branch' => 'main',
        'fqdn' => 'https://shop.example.com',
    ]);
});

function createDeploymentRow(Application $application, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'deploy-'.fake()->unique()->uuid(),
        'commit' => DEPLOYMENT_EMAIL_COMMIT_SHA,
        'commit_message' => "Add checkout page\n\nHandles card and wallet payments.",
        'status' => 'finished',
        ...$attributes,
    ]);
}

function enableCommitDetails(Team $team, bool $enabled = true): void
{
    $team->emailNotificationSettings->update(['deployment_commit_details_email_notifications' => $enabled]);
    $team->refresh();
}

function renderDeploymentMail(string $notificationClass, Application $application, ApplicationDeploymentQueue $deployment, Team $team, ?ApplicationPreview $preview = null): string
{
    return (string) (new $notificationClass($application, $deployment->deployment_uuid, $preview, $deployment))->toMail($team)->render();
}

it('defaults the commit details setting to off', function () {
    expect($this->team->emailNotificationSettings->fresh()->deployment_commit_details_email_notifications)->toBeFalse();
});

it('includes commit details in deployment emails when enabled', function (string $notificationClass) {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application);

    $html = renderDeploymentMail($notificationClass, $this->application, $deployment, $this->team);

    expect($html)
        ->toContain('3f9c2a7')
        ->toContain('https://github.com/acme/shop/commit/'.DEPLOYMENT_EMAIL_COMMIT_SHA)
        ->toContain('main')
        ->toContain('Add checkout page')
        ->toContain('Handles card and wallet payments.');
})->with([DeploymentSuccess::class, DeploymentFailed::class]);

it('leaves deployment emails unchanged when the setting is off', function (string $notificationClass) {
    enableCommitDetails($this->team, false);
    $deployment = createDeploymentRow($this->application);

    $html = renderDeploymentMail($notificationClass, $this->application, $deployment, $this->team);

    expect($html)
        ->not->toContain('3f9c2a7')
        ->not->toContain('Add checkout page')
        ->toContain('View Deployment Logs');
})->with([DeploymentSuccess::class, DeploymentFailed::class]);

it('hides the commit block when the deployment has no commit information', function () {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application, ['commit' => 'HEAD', 'commit_message' => null]);

    $html = renderDeploymentMail(DeploymentSuccess::class, $this->application, $deployment, $this->team);

    expect($html)
        ->not->toContain('Commit:')
        ->not->toContain('Commit message:')
        ->toContain('View Deployment Logs');
});

it('shows the commit for preview deployments without the base branch', function () {
    enableCommitDetails($this->team);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/acme/shop/pull/42',
        'fqdn' => 'https://pr-42.shop.example.com',
    ]);
    $deployment = createDeploymentRow($this->application, ['pull_request_id' => 42]);

    $html = renderDeploymentMail(DeploymentSuccess::class, $this->application, $deployment, $this->team, $preview);

    expect($html)
        ->toContain('Pull request #42')
        ->toContain('3f9c2a7')
        ->toContain('Add checkout page')
        ->not->toContain(' on main');
});

it('escapes html and markdown in commit messages', function () {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application, [
        'commit_message' => "Fix <script>alert(1)</script> bug\n[Verify your account](https://evil.example.com)\n# Big heading",
    ]);

    $html = renderDeploymentMail(DeploymentSuccess::class, $this->application, $deployment, $this->team);

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;')
        ->not->toContain('href="https://evil.example.com"')
        ->toContain('[Verify your account](https://evil.example.com)')
        ->not->toContain('<h1>Big heading</h1>')
        ->toContain('# Big heading');
});

it('lets team admins toggle commit details from email notification settings', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);
    Cache::flush();

    Livewire::test(NotificationEmail::class)
        ->assertSet('deploymentCommitDetailsEmailNotifications', false)
        ->set('deploymentCommitDetailsEmailNotifications', true)
        ->call('instantSaveDeploymentCommitDetails')
        ->assertHasNoErrors();

    expect($this->team->emailNotificationSettings->fresh()->deployment_commit_details_email_notifications)->toBeTrue();
});

it('does not let team members change the commit details setting', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    Cache::flush();

    Livewire::test(NotificationEmail::class)
        ->set('deploymentCommitDetailsEmailNotifications', true)
        ->call('instantSaveDeploymentCommitDetails')
        ->assertSet('deploymentCommitDetailsEmailNotifications', false);

    expect($this->team->emailNotificationSettings->fresh()->deployment_commit_details_email_notifications)->toBeFalse();
});

it('does not let admins of another team change the commit details setting', function () {
    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'admin']);
    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);
    Cache::flush();

    Livewire::test(NotificationEmail::class)
        ->set('deploymentCommitDetailsEmailNotifications', true)
        ->call('instantSaveDeploymentCommitDetails');

    expect($this->team->emailNotificationSettings->fresh()->deployment_commit_details_email_notifications)->toBeFalse()
        ->and($otherTeam->emailNotificationSettings->fresh()->deployment_commit_details_email_notifications)->toBeTrue();
});

function bigMarkdownCommitMessage(): string
{
    return implode("\n", [
        'feat(checkout): rebuild payment flow',
        '',
        '# Heading one',
        'Setext heading',
        '==============',
        '## Heading two',
        '- bullet one',
        '* bullet two',
        '+ bullet three',
        '1. first step',
        '2. second step',
        '> quoted text',
        '    indented code line',
        '```php',
        'echo "hello";',
        '```',
        '| Column A | Column B |',
        '| -------- | -------- |',
        '| cell 1   | cell 2   |',
        '**bold** _italic_ ~~strike~~ `inline code`',
        '[Reset your password](https://evil.example.com/reset)',
        '![tracking pixel](https://evil.example.com/pixel.png)',
        '<https://evil.example.com/autolink>',
        '<img src="https://evil.example.com/x.png" onerror="alert(1)">',
        '<a href="https://evil.example.com">click me</a>',
        '---',
        'Co-authored-by: Jane Doe <jane@example.com>',
        'Emoji check 🚀 ✅',
        '',
        str_repeat('Long body sentence for truncation. ', 60),
        'THIS_TAIL_SHOULD_BE_TRUNCATED',
    ]);
}

it('renders a big markdown commit message as plain safe text', function () {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application, ['commit_message' => bigMarkdownCommitMessage()]);

    $html = renderDeploymentMail(DeploymentSuccess::class, $this->application, $deployment, $this->team);

    expect($html)
        ->toContain('feat(checkout): rebuild payment flow')
        ->toContain('# Heading one')
        ->toContain('==============')
        ->toContain('- bullet one')
        ->toContain('1. first step')
        ->toContain('```php')
        ->toContain('| Column A | Column B |')
        ->toContain('**bold** _italic_ ~~strike~~ `inline code`')
        ->toContain('Co-authored-by: Jane Doe &lt;jane@example.com&gt;')
        ->toContain('Emoji check 🚀 ✅')
        ->not->toContain("\u{FFFD}")
        ->toContain('Long body sentence for truncation.')
        ->not->toContain('THIS_TAIL_SHOULD_BE_TRUNCATED')
        ->not->toContain('href="https://evil.example.com')
        ->not->toContain('src="https://evil.example.com')
        ->not->toContain('<img')
        ->not->toContain('<a href="https://evil')
        ->not->toContain('<h1>')
        ->not->toContain('<h2>')
        ->not->toContain('<ul>')
        ->not->toContain('<ol>')
        ->not->toContain('<blockquote>')
        ->not->toContain('<pre>')
        ->not->toContain('<table>')
        ->not->toContain('<strong>bold</strong>')
        ->toContain('---<br />');
});

it('keeps the email working when a commit message has invalid utf-8 bytes', function () {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application, ['commit_message' => "Fix caf\xE9 menu (latin-1 byte)"]);

    $html = renderDeploymentMail(DeploymentSuccess::class, $this->application, $deployment, $this->team);

    expect($html)
        ->toContain('Fix caf')
        ->toContain('menu (latin-1 byte)')
        ->toContain('View Deployment Logs');
});

it('builds commit details without querying the deployment queue', function (string $notificationClass) {
    enableCommitDetails($this->team);
    $deployment = createDeploymentRow($this->application);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $notification = new $notificationClass($this->application, $deployment->deployment_uuid, null, $deployment);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $query): bool => str_contains($query, 'application_deployment_queues')))->toBeEmpty()
        ->and($notification->commit_sha)->toBe(DEPLOYMENT_EMAIL_COMMIT_SHA);
})->with([DeploymentSuccess::class, DeploymentFailed::class]);

it('keeps the old constructor working without a deployment record', function () {
    enableCommitDetails($this->team);

    $html = (string) (new DeploymentSuccess($this->application, 'legacy-call'))->toMail($this->team)->render();

    expect($html)
        ->toContain('View Deployment Logs')
        ->not->toContain('Commit message:');
});

it('passes the in-memory deployment record from the deployment job', function () {
    $job = file_get_contents(app_path('Jobs/ApplicationDeploymentJob.php'));

    expect($job)->toContain('new $notificationClass($this->application, $this->deployment_uuid, $this->preview, $this->application_deployment_queue)');
});
