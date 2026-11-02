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

it('suppresses logs when ANSI normalization exceeds the PCRE backtrack limit', function () {
    $backtrackLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');
    set_error_handler(static fn (): bool => true, E_DEPRECATED);

    try {
        $result = (new SensitiveDataRedactor)->redactText("\e[31msecret");
    } finally {
        restore_error_handler();
        ini_set('pcre.backtrack_limit', $backtrackLimit);
    }

    expect($result)->toBe(SensitiveDataRedactor::FAILURE_MESSAGE);
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
        ->toBe('password="'.REDACTED.'" PASSWORD=\''.REDACTED.'\' next=1');
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

it('redacts keys that end with a sensitive name', function () {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactValue(['smtp_password' => 'hunter2', 'githubToken' => 'abc', 'X-Webhook-Secret' => 'def', 'token_count' => 5, 'password_reset_at' => 'today']))
        ->toBe(['smtp_password' => REDACTED, 'githubToken' => REDACTED, 'X-Webhook-Secret' => REDACTED, 'token_count' => 5, 'password_reset_at' => 'today'])
        ->and($redactor->redactText('smtp_password=hunter2 "db-password": "p w" max_tokens=100'))
        ->toBe('smtp_password='.REDACTED.' "db-password": "'.REDACTED.'" max_tokens=100');
});

it('keeps quotes around redacted assignment values so JSON stays valid', function () {
    $redactor = new SensitiveDataRedactor;

    $json = $redactor->redactText('{"token": "abc", "password": "a\\"b", "name": "demo"}');

    expect($json)->toBe('{"token": "'.REDACTED.'", "password": "'.REDACTED.'", "name": "demo"}')
        ->and(json_decode($json, true))->toBe(['token' => REDACTED, 'password' => REDACTED, 'name' => 'demo'])
        ->and($redactor->redactText("{'secret': 'abc'}"))->toBe("{'secret': '".REDACTED."'}");
});

it('does not treat prose or working directories as secret assignments', function () {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactText('Failed to refresh token: Connection refused'))->toBe('Failed to refresh token: Connection refused')
        ->and($redactor->redactText('PWD=/artifacts/abc'))->toBe('PWD=/artifacts/abc');
});

it('still redacts real secret assignments', function (string $text, string $expected) {
    expect((new SensitiveDataRedactor)->redactText($text))->toBe($expected);
})->with([
    'equals' => ['password=abc', 'password='.REDACTED],
    'quoted key and value' => ['"password": "abc"', '"password": "'.REDACTED.'"'],
    'quoted key with bare value' => ['"token": abc123,', '"token": '.REDACTED.','],
    'env style' => ['API_TOKEN=abc', 'API_TOKEN='.REDACTED],
    'env style with colon' => ['POSTGRES_PASSWORD: abc next', 'POSTGRES_PASSWORD: '.REDACTED.' next'],
    'compound key with colon' => ['smtp_password: abc next', 'smtp_password: '.REDACTED.' next'],
    'yaml value at line end' => ["password: correcthorse\nnext", 'password: '.REDACTED."\nnext"],
    'value that looks like a secret' => ['Password: hunter2 was set', 'Password: '.REDACTED.' was set'],
    'authorization header' => ['Authorization: Bearer x', 'Authorization: '.REDACTED],
]);

it('replaces short locked values only as whole tokens', function () {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactText('database ab_c cab 9ab', lockedSecrets: ['ab']))->toBe('database ab_c cab 9ab')
        ->and($redactor->redactText('password xy user=xy "xy" postgres://u:xy@host', lockedSecrets: ['xy']))
        ->toBe('password '.REDACTED.' user='.REDACTED.' "'.REDACTED.'" postgres://u:'.REDACTED.'@host')
        ->and($redactor->redactText('éxyé xy', lockedSecrets: ['xy']))->toBe('éxyé '.REDACTED);
});

it('replaces encoded short locked values and long locked values longest first', function () {
    $result = (new SensitiveDataRedactor)->redactText('p%40s p\\"s\\"q long-locked-value long-locked-value-2 inside-long-locked-valueX', lockedSecrets: ['p@s', 'p"s"q', 'long-locked-value', 'long-locked-value-2']);

    expect($result)->toBe(REDACTED.' '.REDACTED.' '.REDACTED.' '.REDACTED.' inside-'.REDACTED.'X');
});

it('falls back to line by line redaction when a chunk exceeds PCRE limits', function () {
    $text = "password=first-secret\n".str_repeat('a-', 40000)."\nToken=locked-value-123 next=1\nlast line";

    $result = (new SensitiveDataRedactor)->redactText($text, lockedSecrets: ['locked-value-123']);

    expect($result)->toBe('password='.REDACTED."\n".SensitiveDataRedactor::FAILURE_MESSAGE."\nToken=".REDACTED." next=1\nlast line");
});

it('redacts an unterminated private key followed by a lot of output without suppressing other lines', function () {
    $text = "before password=abc\n-----BEGIN PRIVATE KEY-----\n".str_repeat("secret-key-line\n", 70000).'tail';

    $result = (new SensitiveDataRedactor)->redactText($text);

    expect($result)->not->toContain('secret-key-line')
        ->not->toContain(SensitiveDataRedactor::FAILURE_MESSAGE)
        ->toStartWith('before password='.REDACTED."\n");
});

it('keeps the quotes around a quoted shell assignment and hides its whole value', function (string $text, string $expected) {
    expect((new SensitiveDataRedactor)->redactText($text))->toBe($expected);
})->with([
    'single quoted build arg' => [
        "docker build --build-arg 'COOLIFY_BUILD_SECRETS_HASH=abc123def' --build-arg 'COOLIFY_URL'",
        "docker build --build-arg 'COOLIFY_BUILD_SECRETS_HASH=".REDACTED."' --build-arg 'COOLIFY_URL'",
    ],
    'double quoted value with a space' => [
        'export "DB_PASSWORD=first second" && next',
        'export "DB_PASSWORD='.REDACTED.'" && next',
    ],
    'single quoted value with a space' => [
        "--env 'api_token=abc def' --rm",
        "--env 'api_token=".REDACTED."' --rm",
    ],
]);
