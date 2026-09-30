<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{input: string, copied: string}
 */
function renderedTwoFactorSetupUrl(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);

    $input = null;
    foreach ($xpath->query('//input[@readonly]') as $node) {
        if (str_starts_with(html_entity_decode($node->getAttribute('value')), 'otpauth')) {
            $input = $node;
            break;
        }
    }
    expect($input)->not->toBeNull();

    preg_match('/@click="copy\(await \(([^"]*otpauth[^"]*)\)\)"/', $html, $matches);
    expect($matches)->toHaveKey(1);
    $javascriptString = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

    return [
        'input' => $input->getAttribute('value'),
        'copied' => json_decode('"'.str_replace("\\'", "'", trim($javascriptString, "'")).'"'),
    ];
}

it('renders the 2fa setup url escaped exactly once', function () {
    config()->set('app.name', 'Coolify & Co');

    $user = User::factory()->create(['name' => 'Two Factor User', 'email' => 'first+2fa@example.com']);
    $user->forceFill([
        'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
    ])->save();

    $this->actingAs($user);
    session(['status' => 'two-factor-authentication-enabled']);

    $expectedUrl = $user->fresh()->twoFactorQrCodeUrl();
    $rendered = renderedTwoFactorSetupUrl(Livewire::test(ProfileIndex::class)->html());

    expect($rendered['input'])->toBe($expectedUrl)
        ->and($rendered['copied'])->toBe($expectedUrl);

    $parts = parse_url($rendered['copied']);
    parse_str($parts['query'], $query);

    expect($parts['scheme'])->toBe('otpauth')
        ->and($parts['host'])->toBe('totp')
        ->and(rawurldecode(ltrim($parts['path'], '/')))->toBe('Coolify & Co:first+2fa@example.com')
        ->and($query['issuer'])->toBe('Coolify & Co')
        ->and($query['secret'])->toBe(decrypt($user->fresh()->two_factor_secret));
});
