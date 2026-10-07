<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], [
        'fqdn' => 'https://coolify.example.com',
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_from_address' => 'coolify@example.com',
        'smtp_from_name' => 'Coolify',
    ]));
    Once::flush();
});

it('builds the verification link from the instance URL, not the request host', function () {
    $user = User::factory()->create(['email' => 'host-check@example.com', 'email_verified_at' => null]);

    $html = null;
    Event::listen(MessageSending::class, function (MessageSending $event) use (&$html) {
        $html = $event->message->getHtmlBody();

        return false;
    });

    $this->app->instance('request', Request::create('http://attacker.test/verify'));

    $user->sendVerificationEmail();

    expect($html)->not->toBeNull();
    preg_match('~href="([^"]*/email/verify/[^"]+)"~', $html, $matches);
    $url = html_entity_decode($matches[1] ?? '');

    expect($url)->toStartWith('https://coolify.example.com/email/verify/')
        ->and($html)->not->toContain('attacker.test');

    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->refresh()->email_verified_at)->not->toBeNull();
});
