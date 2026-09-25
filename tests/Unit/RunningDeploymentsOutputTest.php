<?php

it('shows only the active deployment count by default', function () {
    $output = formatRunningDeploymentsOutput(1, [[
        'application_name' => 'secret-application',
        'server_name' => 'secret-server',
        'deployment_url' => 'https://example.com/secret-deployment',
        'team_members' => ['member@example.com'],
        'created_at' => '2026-09-15 08:52:42',
        'horizon_job_id' => 'secret-job-id',
    ]]);

    expect($output)
        ->toBe("\n=== Running Deployments ===\nTotal active deployments: 1\n")
        ->not->toContain('secret-application')
        ->not->toContain('https://example.com/secret-deployment')
        ->not->toContain('member@example.com');
});

it('shows all deployment details when requested', function () {
    $output = formatRunningDeploymentsOutput(1, [[
        'application_name' => 'example-application',
        'server_name' => 'example-server',
        'deployment_url' => 'https://example.com/deployment',
        'team_members' => ['member@example.com'],
        'created_at' => '2026-09-15 08:52:42',
        'horizon_job_id' => 'example-job-id',
    ]], true);

    expect($output)
        ->toContain('Deployment #1:')
        ->toContain('Application: example-application')
        ->toContain('Server: example-server')
        ->toContain('Started: 2026-09-15 08:52:42')
        ->toContain('URL: https://example.com/deployment')
        ->toContain('Team members: member@example.com')
        ->toContain('Horizon Job ID: example-job-id');
});
