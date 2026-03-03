<?php

use App\Models\GithubRunnerExecution;

it('prefers the direct workflow job html url when available', function () {
    $execution = new GithubRunnerExecution([
        'workflow_job_id' => 987654,
        'repository_full_name' => 'test-org/test-repo',
        'workflow_job_html_url' => 'https://github.com/test-org/test-repo/actions/runs/111/job/987654',
    ]);

    expect($execution->workflowJobUrl())->toBe('https://github.com/test-org/test-repo/actions/runs/111/job/987654');
});

it('builds a fallback github actions search url when direct url is missing', function () {
    $execution = new GithubRunnerExecution([
        'workflow_job_id' => 987654,
        'repository_full_name' => 'test-org/test-repo',
        'workflow_job_html_url' => null,
    ]);

    expect($execution->workflowJobUrl())->toBe('https://github.com/test-org/test-repo/actions?query=987654');
});

it('returns null when there is not enough data to build a workflow url', function () {
    $execution = new GithubRunnerExecution([
        'workflow_job_id' => null,
        'repository_full_name' => null,
        'workflow_job_html_url' => null,
    ]);

    expect($execution->workflowJobUrl())->toBeNull();
});
