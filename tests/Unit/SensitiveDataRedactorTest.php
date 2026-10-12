<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Services\Security\SensitiveDataRedactor;

it('redacts known secret values and encoded variants longest first', function () {
    $redactor = new SensitiveDataRedactor;
    $knownSecrets = ['coolify-secret', 'coolify-secret-longer', 'secret with spaces', 'quote"secret'];

    $result = $redactor->redactText('coolify-secret-longer coolify-secret secret%20with%20spaces quote\"secret', $knownSecrets);

    expect($result)->not->toContain('coolify-secret')
        ->not->toContain('secret%20with%20spaces')
        ->not->toContain('quote\"secret')
        ->toBe(REDACTED.' '.REDACTED.' '.REDACTED.' '.REDACTED);
});

it('does not replace short known values in unstructured text', function () {
    $result = (new SensitiveDataRedactor)->redactText('mode=dev enabled=yes count=1', ['dev', 'yes', 1]);

    expect($result)->toBe('mode=dev enabled=yes count=1');
});

it('redacts sensitive keys recursively including short values', function () {
    $result = (new SensitiveDataRedactor)->redactValue([
        'name' => 'demo',
        'PASSWORD' => 'x',
        'request' => ['Authorization' => 'Bearer short', 'set-cookie' => 'session=secret', 'status' => 200],
    ]);

    expect($result)->toBe([
        'name' => 'demo',
        'PASSWORD' => REDACTED,
        'request' => ['Authorization' => REDACTED, 'set-cookie' => REDACTED, 'status' => 200],
    ]);
});

it('redacts complete authorization headers with opaque bearer tokens', function () {
    $secret = 'coolify-canary-secret-scheduled';
    $result = (new SensitiveDataRedactor)->redactText("Authorization: Bearer {$secret}\nstatus=ok");

    expect($result)
        ->not->toContain($secret)
        ->toBe('Authorization: '.REDACTED."\nstatus=ok");
});

it('preserves current generic redaction behavior', function () {
    $privateKey = "-----BEGIN PRIVATE KEY-----\nsecret-key-data\n-----END PRIVATE KEY-----";
    $result = (new SensitiveDataRedactor)->redactText("\e[31mhttps://user:password@example.com\e[0m Bearer aaa.bbb.ccc {$privateKey}");

    expect($result)->not->toContain("\e[31m")
        ->not->toContain('password')
        ->not->toContain('aaa.bbb.ccc')
        ->not->toContain('secret-key-data')
        ->toContain('https://user:'.REDACTED.'@example.com')
        ->toContain('Bearer '.REDACTED);
});

it('normalizes invalid utf8 and removes unsafe control characters', function () {
    $result = (new SensitiveDataRedactor)->redactText("valid\xC3\x28\x00\x07\nnext");

    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue()
        ->and($result)->not->toContain("\x00")
        ->and($result)->not->toContain("\x07")
        ->and($result)->toContain("\nnext");
});

it('redacts large text completely without truncating it', function () {
    $line = str_repeat('a', 1000)."\n";
    $text = str_repeat($line, 600).'password=coolify-canary-tail-secret'."\n".'last line';

    $result = (new SensitiveDataRedactor)->redactText($text);

    expect(strlen($result))->toBeGreaterThan(600000)
        ->and($result)->not->toContain('coolify-canary-tail-secret')
        ->and($result)->toEndWith('password='.REDACTED."\nlast line");
});

it('keeps a private key that crosses a chunk boundary redacted', function () {
    $privateKey = "-----BEGIN PRIVATE KEY-----\n".str_repeat("secret-key-line\n", 20000).'-----END PRIVATE KEY-----';
    $text = str_repeat(str_repeat('a', 1000)."\n", 250).$privateKey."\nafter";

    $result = (new SensitiveDataRedactor)->redactText($text);

    expect($result)->not->toContain('secret-key-line')
        ->and($result)->toEndWith(REDACTED."\nafter");
});

it('always redacts short locked secrets but not short unlocked values', function () {
    $result = (new SensitiveDataRedactor)->redactText('pin 4821 mode dev', ['dev'], ['4821']);

    expect($result)->toBe('pin '.REDACTED.' mode dev');
});

it('redacts complete quoted assignment values that contain spaces', function () {
    $result = (new SensitiveDataRedactor)->redactText("password=\"first second\" PASSWORD='third fourth' next=1");

    expect($result)
        ->not->toContain('first')
        ->not->toContain('second')
        ->not->toContain('third')
        ->not->toContain('fourth')
        ->toBe('password='.REDACTED.' PASSWORD='.REDACTED.' next=1');
});

it('redacts arrayable objects and hides unknown objects', function () {
    $secret = 'coolify-canary-object-secret';
    $unknown = new class
    {
        public string $value = 'coolify-canary-object-secret';
    };

    $result = (new SensitiveDataRedactor)->redactValue([
        'collection' => collect(['password' => $secret, 'name' => 'demo']),
        'unknown' => $unknown,
        'status' => ApplicationDeploymentStatus::FINISHED,
    ]);

    expect(serialize($result))->not->toContain($secret)
        ->and($result['collection'])->toBe(['password' => REDACTED, 'name' => 'demo'])
        ->and($result['unknown'])->toStartWith('[object ')
        ->and($result['status'])->toBe(ApplicationDeploymentStatus::FINISHED);
});
