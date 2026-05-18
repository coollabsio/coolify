<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

    it('returns false for null sentinel token', function () {
        expect(ServerSetting::isValidSentinelToken(null))->toBeFalse();
    });

    it('rejects the reported PoC payload', function () {
        expect(ServerSetting::isValidSentinelToken('abc" ; id >/tmp/coolify_poc_sentinel ; echo "'))->toBeFalse();
    });
});

describe('ServerSetting::ensureValidSentinelToken', function () {
    it('regenerates empty sentinel token via ensureValidSentinelToken', function () {
        $settings = $this->server->settings;
        DB::table('server_settings')->where('id', $settings->id)->update(['sentinel_token' => '']);

        $settings->refresh();
        $token = $settings->ensureValidSentinelToken();

        expect($token)->not->toBeEmpty();
        expect(ServerSetting::isValidSentinelToken($token))->toBeTrue();
        expect($settings->fresh()->sentinel_token)->toBe($token);
    });

    it('regenerates token when stored value cannot be decrypted', function () {
        $settings = $this->server->settings;
        DB::table('server_settings')->where('id', $settings->id)->update(['sentinel_token' => 'not-encrypted-junk']);

        $settings->refresh();
        $token = $settings->ensureValidSentinelToken();

        expect(ServerSetting::isValidSentinelToken($token))->toBeTrue();
        expect($settings->fresh()->sentinel_token)->toBe($token);
    });

    it('returns existing valid token without regenerating', function () {
        $settings = $this->server->settings;
        $original = $settings->sentinel_token;

        $token = $settings->ensureValidSentinelToken();

        expect($token)->toBe($original);
    });

    it('throws RuntimeException only when regeneration also fails', function () {
        $settings = $this->server->settings;
        DB::table('server_settings')->where('id', $settings->id)->update(['sentinel_token' => '']);

        $stub = new class extends ServerSetting
        {
            protected $table = 'server_settings';

            public function generateSentinelToken(bool $save = true, bool $ignoreEvent = false): string
            {
                DB::table('server_settings')->where('id', $this->id)->update([
                    'sentinel_token' => encrypt('invalid token with spaces!'),
                ]);

                return '';
            }
        };
        $stub->setRawAttributes($settings->fresh()->getAttributes(), true);
        $stub->exists = true;

        expect(fn () => $stub->ensureValidSentinelToken())
            ->toThrow(RuntimeException::class, 'Sentinel token invalid after regeneration');
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

    it('returns the same value the cast reads back', function () {
        $settings = $this->server->settings;
        $returned = $settings->generateSentinelToken(save: true, ignoreEvent: true);

        expect($settings->fresh()->sentinel_token)->toBe($returned);
    });
});
