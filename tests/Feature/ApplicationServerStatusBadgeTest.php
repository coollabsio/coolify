<?php

function renderApplicationServerStatusBadge(?bool $serverStatus, string $status = 'running', bool $hasAdditionalServers = false): string
{
    $application = new class($serverStatus, $status, $hasAdditionalServers)
    {
        public function __construct(
            public ?bool $server_status,
            public string $status,
            private bool $hasAdditionalServers,
        ) {}

        public function additional_servers(): object
        {
            return new class($this->hasAdditionalServers)
            {
                public function __construct(private bool $exists) {}

                public function exists(): bool
                {
                    return $this->exists;
                }
            };
        }
    };

    return view('livewire.project.application.server-status-badge', [
        'application' => $application,
    ])->render();
}

it('does not show the unreachable server badge when server status is unknown', function () {
    $html = renderApplicationServerStatusBadge(null);

    expect($html)->not->toContain('One or more servers are unreachable or misconfigured.');
});

it('shows the unreachable server badge only when server status is false', function () {
    $html = renderApplicationServerStatusBadge(false);

    expect($html)->toContain('One or more servers are unreachable or misconfigured.');
});
