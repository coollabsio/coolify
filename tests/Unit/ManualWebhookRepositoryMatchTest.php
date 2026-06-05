<?php

use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;

function manualWebhookRepositoryPath(?string $repository): ?string
{
    $matcher = new class
    {
        use MatchesManualWebhookApplications;

        public function canonical(?string $repository): ?string
        {
            return $this->canonicalManualWebhookRepository($repository);
        }
    };

    return $matcher->canonical($repository);
}

it('normalizes scp-style ssh repositories with non-git users', function () {
    expect(manualWebhookRepositoryPath('gitea@git.example.com:Test/Repo.git'))->toBe('Test/Repo')
        ->and(manualWebhookRepositoryPath('forgejo@git.example.com:2222/Test/Repo.git'))->toBe('Test/Repo');
});
