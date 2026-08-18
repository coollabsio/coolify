<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
});

describe('isAnyNotificationEnabled', function () {
    test('returns false when no notifications are enabled', function () {
        expect($this->team->isAnyNotificationEnabled())->toBeFalse();
    });

    test('returns true when email notifications are enabled', function () {
        $this->team->emailNotificationSettings->update(['smtp_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });

    test('returns true when discord notifications are enabled', function () {
        $this->team->discordNotificationSettings->update(['discord_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });

    test('returns true when slack notifications are enabled', function () {
        $this->team->slackNotificationSettings->update(['slack_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });

    test('returns true when telegram notifications are enabled', function () {
        $this->team->telegramNotificationSettings->update(['telegram_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });

    test('returns true when pushover notifications are enabled', function () {
        $this->team->pushoverNotificationSettings->update(['pushover_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });

    test('returns true when webhook notifications are enabled', function () {
        $this->team->webhookNotificationSettings->update(['webhook_enabled' => true]);

        expect($this->team->isAnyNotificationEnabled())->toBeTrue();
    });
});
