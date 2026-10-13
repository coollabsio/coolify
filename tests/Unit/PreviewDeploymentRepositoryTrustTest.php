<?php

use App\Http\Controllers\Webhook\Concerns\ValidatesPreviewDeploymentRepository;

function previewRepositoryIsTrusted(mixed $source, mixed $target, mixed $webhookRepository, bool $publicEnabled = false): bool
{
    return (new class
    {
        use ValidatesPreviewDeploymentRepository;

        public function check(mixed $source, mixed $target, mixed $webhookRepository, bool $publicEnabled): bool
        {
            return $this->isPreviewDeploymentRepositoryTrusted($source, $target, $webhookRepository, $publicEnabled);
        }
    })->check($source, $target, $webhookRepository, $publicEnabled);
}

it('allows a trusted same-repository preview', function () {
    expect(previewRepositoryIsTrusted(55, 55, 55))->toBeTrue();
});

it('denies fork previews for each provider when public previews are disabled', function (string $provider, mixed $source, mixed $target, mixed $repository) {
    expect(previewRepositoryIsTrusted($source, $target, $repository))->toBeFalse($provider);
})->with([
    'GitLab merge request' => ['gitlab', 999, 55, 55],
    'Gitea pull request' => ['gitea', 999, 55, 55],
    'Bitbucket pull request' => ['bitbucket', '{fork}', '{base}', '{base}'],
]);

it('allows an explicitly approved fork preview', function () {
    expect(previewRepositoryIsTrusted(999, 55, 55, true))->toBeTrue();
});

it('denies missing or ambiguous repository identity', function (mixed $source, mixed $target, mixed $repository) {
    expect(previewRepositoryIsTrusted($source, $target, $repository, true))->toBeFalse();
})->with([
    'missing source' => [null, 55, 55],
    'missing target' => [55, null, 55],
    'missing webhook repository' => [55, 55, null],
    'empty identity' => ['', 55, 55],
]);

it('denies spoofed target repository metadata', function () {
    expect(previewRepositoryIsTrusted(55, 55, 999, true))->toBeFalse();
});

it('gates every non-GitHub webhook preview before it creates or queues a deployment', function (string $controller, int $expectedChecks) {
    $source = file_get_contents(dirname(__DIR__, 2)."/app/Http/Controllers/Webhook/{$controller}.php");

    expect(substr_count($source, 'isPreviewDeploymentRepositoryTrusted('))->toBe($expectedChecks);

    $offset = 0;
    for ($check = 0; $check < $expectedChecks; $check++) {
        $trustCheck = strpos($source, 'isPreviewDeploymentRepositoryTrusted(', $offset);
        $nextQueue = strpos($source, 'queue_application_deployment(', $trustCheck);

        expect($trustCheck)->not->toBeFalse()
            ->and($nextQueue)->not->toBeFalse()
            ->and($trustCheck)->toBeLessThan($nextQueue);

        $offset = $trustCheck + 1;
    }
})->with([
    'GitLab normal and manual webhooks' => ['Gitlab', 2],
    'Gitea signed manual webhook' => ['Gitea', 1],
    'Bitbucket signed manual webhook' => ['Bitbucket', 1],
]);
