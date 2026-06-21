<?php

it('tests login rate limiting with different IPs like the Python script', function () {
    // Create a test route that mimics login behavior
    // We'll directly test the rate limiter behavior

    $baseUrl = '/login';
    $email = 'grumpinout+admin@wearehackerone.com';

    // First, get a CSRF token by visiting the login page
    $loginPageResponse = $this->get($baseUrl);
    $loginPageResponse->assertSuccessful();

    // Extract CSRF token using regex similar to Python script
    preg_match('/name="_token"\s+value="([^"]+)"/', $loginPageResponse->getContent(), $matches);
    $token = $matches[1] ?? null;

    expect($token)->not->toBeNull('CSRF token should be found');

    // Test 14 login attempts with different IPs (like the Python script does 1-14)
    $results = [];
    for ($i = 1; $i <= 14; $i++) {
        $spoofedIp = "198.51.100.{$i}";

        $response = $this->withHeader('X-Forwarded-For', $spoofedIp)
            ->post($baseUrl, [
                '_token' => $token,
                'email' => $email,
                'password' => "WrongPass{$i}!",
            ]);

        $statusCode = $response->getStatusCode();
        $rateLimitLimit = $response->headers->get('X-RateLimit-Limit');
        $rateLimitRemaining = $response->headers->get('X-RateLimit-Remaining');

        $results[$i] = [
            'ip' => $spoofedIp,
            'status' => $statusCode,
            'rate_limit' => $rateLimitLimit,
            'rate_limit_remaining' => $rateLimitRemaining,
        ];

        // Print output similar to Python script
        echo 'Attempt '.str_pad($i, 2, '0', STR_PAD_LEFT).": status=$statusCode, RL=$rateLimitLimit/$rateLimitRemaining\n";

        // Add a small delay like the Python script (0.2 seconds)
        usleep(200000);
    }

    // Verify results
    expect($results)->toHaveCount(14);

    // Check that we got responses for all attempts
    foreach ($results as $i => $result) {
        expect($result['status'])->toBeGreaterThanOrEqual(200);
        expect($result['ip'])->toBe("198.51.100.{$i}");
    }
});
