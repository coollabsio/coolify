<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

describe('ServerSetting::isValidSentinelToken', function () {
    it('accepts alphanumeric tokens', function () {
        expect(ServerSetting::isValidSentinelToken('abc123'))->toBeTrue();
    });

    it('accepts tokens with dots, hyphens, and underscores', function () {
        expect(ServerSetting::isValidSentinelToken('my-token_v2.0'))->toBeTrue();
    });

    it('accepts long base64-like encrypted tokens', function () {
        $token = 'eyJpdiI6IjRGN0V4YnRkZ1p0UXdBPT0iLCJ2YWx1ZSI6IjZqQT0iLCJtYWMiOiIxMjM0NTY3ODkwIn0';
        expect(ServerSetting::isValidSentinelToken($token))->toBeTrue();
    });

    it('accepts tokens with base64 characters (+, /, =)', function () {
        expect(ServerSetting::isValidSentinelToken('abc+def/ghi='))->toBeTrue();
    });

    it('rejects tokens with double quotes', function () {
        expect(ServerSetting::isValidSentinelToken('abc" ; id ; echo "'))->toBeFalse();
    });

    it('rejects tokens with single quotes', function () {
        expect(ServerSetting::isValidSentinelToken("abc' ; id ; echo '"))->toBeFalse();
    });

    it('rejects tokens with semicolons', function () {
        expect(ServerSetting::isValidSentinelToken('abc;id'))->toBeFalse();
    });

    it('rejects tokens with backticks', function () {
        expect(ServerSetting::isValidSentinelToken('abc`id`'))->toBeFalse();
    });

    it('rejects tokens with dollar sign command substitution', function () {
        expect(ServerSetting::isValidSentinelToken('abc$(whoami)'))->toBeFalse();
    });

    it('rejects tokens with spaces', function () {
        expect(ServerSetting::isValidSentinelToken('abc def'))->toBeFalse();
    });

    it('rejects tokens with newlines', function () {
        expect(ServerSetting::isValidSentinelToken("abc\nid"))->toBeFalse();
    });

    it('rejects tokens with pipe operator', function () {
        expect(ServerSetting::isValidSentinelToken('abc|id'))->toBeFalse();
    });

    it('rejects tokens with ampersand', function () {
        expect(ServerSetting::isValidSentinelToken('abc&&id'))->toBeFalse();
    });

    it('rejects tokens with redirection operators', function () {
        expect(ServerSetting::isValidSentinelToken('abc>/tmp/pwn'))->toBeFalse();
    });

    it('rejects empty strings', function () {
        expect(ServerSetting::isValidSentinelToken(''))->toBeFalse();
    });

    it('rejects the reported PoC payload', function () {
        expect(ServerSetting::isValidSentinelToken('abc" ; id >/tmp/coolify_poc_sentinel ; echo "'))->toBeFalse();
    });
});

describe('generated sentinel tokens are valid', function () {
    it('generates tokens that pass format validation', function () {
        $settings = $this->server->settings;
        $settings->generateSentinelToken(save: false, ignoreEvent: true);
        $token = $settings->sentinel_token;

        expect($token)->not->toBeEmpty();
        expect(ServerSetting::isValidSentinelToken($token))->toBeTrue();
    });
});
