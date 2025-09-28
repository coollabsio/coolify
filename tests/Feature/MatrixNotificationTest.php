<?php

use App\Models\MatrixNotificationSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Test;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
});

it('can create matrix notification settings for a team', function () {
    $settings = MatrixNotificationSettings::factory()->create([
        'team_id' => $this->team->id,
        'matrix_enabled' => true,
        'matrix_homeserver_url' => 'https://matrix.org',
        'matrix_room_id' => '!test:matrix.org',
        'matrix_access_token' => 'test_token',
        'matrix_friendly_name' => 'Test Matrix',
    ]);

    expect($settings->team_id)->toBe($this->team->id);
    expect($settings->matrix_enabled)->toBeTrue();
    expect($settings->matrix_homeserver_url)->toBe('https://matrix.org');
    expect($settings->matrix_room_id)->toBe('!test:matrix.org');
    expect($settings->matrix_access_token)->toBe('test_token');
    expect($settings->matrix_friendly_name)->toBe('Test Matrix');
});

it('has correct default notification settings', function () {
    $settings = MatrixNotificationSettings::factory()->create(['team_id' => $this->team->id]);

    expect($settings->deployment_success_matrix_notifications)->toBeFalse();
    expect($settings->deployment_failure_matrix_notifications)->toBeTrue();
    expect($settings->backup_failure_matrix_notifications)->toBeTrue();
    expect($settings->server_unreachable_matrix_notifications)->toBeTrue();
});

it('belongs to a team', function () {
    $settings = MatrixNotificationSettings::factory()->create(['team_id' => $this->team->id]);

    expect($settings->team->id)->toBe($this->team->id);
});

it('can check if matrix notifications are enabled', function () {
    $disabledSettings = MatrixNotificationSettings::factory()->create([
        'team_id' => $this->team->id,
        'matrix_enabled' => false,
    ]);

    $enabledSettings = MatrixNotificationSettings::factory()->create([
        'team_id' => $this->team->id,
        'matrix_enabled' => true,
    ]);

    expect($disabledSettings->isEnabled())->toBeFalse();
    expect($enabledSettings->isEnabled())->toBeTrue();
});

it('encrypts sensitive matrix fields', function () {
    $settings = MatrixNotificationSettings::factory()->create([
        'team_id' => $this->team->id,
        'matrix_homeserver_url' => 'https://matrix.org',
        'matrix_room_id' => '!test:matrix.org',
        'matrix_access_token' => 'test_token',
    ]);

    // Fields should be encrypted in the database
    $rawData = $settings->getAttributes();
    expect($rawData['matrix_homeserver_url'])->not->toBe('https://matrix.org');
    expect($rawData['matrix_room_id'])->not->toBe('!test:matrix.org');
    expect($rawData['matrix_access_token'])->not->toBe('test_token');

    // But accessible normally through the model
    expect($settings->matrix_homeserver_url)->toBe('https://matrix.org');
    expect($settings->matrix_room_id)->toBe('!test:matrix.org');
    expect($settings->matrix_access_token)->toBe('test_token');
});

it('can render matrix notification livewire component', function () {
    $response = $this->get(route('notifications.matrix'));

    $response->assertStatus(200);
    $response->assertSeeLivewire('notifications.matrix');
});

it('can send test matrix notification', function () {
    Notification::fake();

    $settings = MatrixNotificationSettings::factory()->create([
        'team_id' => $this->team->id,
        'matrix_enabled' => true,
        'matrix_homeserver_url' => 'https://matrix.org',
        'matrix_room_id' => '!test:matrix.org',
        'matrix_access_token' => 'test_token',
    ]);

    $this->team->notify(new Test(channel: 'matrix'));

    Notification::assertSentTo($this->team, Test::class);
});