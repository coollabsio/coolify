<?php

use App\Http\Controllers\Api\DatabasesController;
use App\Http\Controllers\Api\ServiceApplicationsController;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Http\Middleware\ApiAllowed;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('limits login attempts by normalized email independent of IP', function () {
    $limiter = RateLimiter::limiter('login');
    $first = Request::create('/login', 'POST', ['email' => 'First.Name+tag@gmail.com'], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
    $second = Request::create('/login', 'POST', ['email' => 'firstname@gmail.com'], [], [], ['REMOTE_ADDR' => '198.51.100.10']);

    $firstLimits = $limiter($first);
    $secondLimits = $limiter($second);

    expect($firstLimits)->toHaveCount(2)
        ->and($firstLimits[0]->key)->not->toBe($secondLimits[0]->key)
        ->and($firstLimits[1]->key)->toBe($secondLimits[1]->key);
});

it('restricts user broadcast channels to their own ID', function () {
    $callback = Broadcast::getChannels()['user.{userId}'];
    $user = User::factory()->create();

    expect($callback($user, (int) $user->id))->toBeTrue()
        ->and($callback($user, (int) $user->id + 1))->toBeFalse();
});

it('does not require email verification for protected web routes', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create(['email_verified_at' => null]);
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();

    $this->actingAs($user)->get('/analytics')->assertOk();
});

it('applies the API allowlist to MCP and MCP switch routes', function () {
    $mcp = Route::getRoutes()->match(Request::create('/mcp', 'POST'));
    $enable = Route::getRoutes()->match(Request::create('/api/v1/mcp/enable', 'POST'));
    $disable = Route::getRoutes()->match(Request::create('/api/v1/mcp/disable', 'POST'));

    expect($mcp)->not->toBeNull()
        ->and($mcp->gatherMiddleware())->toContain(ApiAllowed::class)
        ->and($enable->gatherMiddleware())->toContain(ApiAllowed::class)
        ->and($disable->gatherMiddleware())->toContain(ApiAllowed::class);
});

it('throttles every manual webhook route', function (string $provider) {
    $route = Route::getRoutes()->match(Request::create("/webhooks/source/{$provider}/events/manual", 'POST'));

    expect($route->gatherMiddleware())->toContain('throttle:60,1');
})->with(['github', 'gitlab', 'bitbucket', 'gitea']);

it('does not reveal how many applications share a manual webhook repository', function () {
    $helper = new class
    {
        use MatchesManualWebhookApplications;

        public function reply(array $payloads): string
        {
            return $this->manualWebhookResponse(collect($payloads))->getContent();
        }
    };
    $failure = ['status' => 'failed', 'message' => 'Invalid signature.'];

    expect($helper->reply([$failure, $failure]))->toBe($helper->reply([$failure]));
    expect($helper->reply([$failure, ['status' => 'success', 'message' => 'queued']]))
        ->not->toContain('Invalid signature.');
});

it('never exposes a shown-once database variable even with sensitive read access', function () {
    $variable = new EnvironmentVariable;
    $variable->forceFill([
        'key' => 'SECRET',
        'value' => 'secret-value',
        'is_shown_once' => true,
    ]);
    request()->attributes->set('can_read_sensitive', true);

    $method = new ReflectionMethod(DatabasesController::class, 'removeSensitiveEnvData');
    $result = $method->invoke(new DatabasesController, $variable);

    expect($result)->not->toHaveKey('value')
        ->not->toHaveKey('real_value');
});

it('does not include nested service and server details in a service application response', function () {
    $application = new ServiceApplication;
    $application->forceFill(['name' => 'app']);
    $application->setRelation('service', (new Service)->forceFill(['name' => 'service']));
    $method = new ReflectionMethod(ServiceApplicationsController::class, 'removeSensitiveData');

    $result = $method->invoke(new ServiceApplicationsController, $application);

    expect($result)->not->toHaveKey('service');
});
