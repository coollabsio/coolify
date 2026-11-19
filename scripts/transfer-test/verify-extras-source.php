<?php

// Checks on the source (A) after the debian-root transfer of extras-e2e.sh. Prints PASS/FAIL lines.

use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\ManagedDnsRecord;
use App\Models\Server;

$check = function (string $name, bool $ok, string $detail = '') {
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($detail !== '' ? " ({$detail})" : '')."\n";
};

$server = Server::where('uuid', 'development-qemu-debian-root')->first();
$migration = (array) data_get($server?->server_metadata, 'migration');
$messages = collect($migration['log'] ?? [])->pluck('message');
$check('source is transferred away', $server?->isTransferredAway() === true);
$check('transfer succeeded', ($migration['status'] ?? null) === 'succeeded', (string) ($migration['error'] ?? ''));
$check('precheck NOTE about resources on the other server', $messages->contains(fn ($m) => str_starts_with($m, "NOTE: project 'Second Server' also has 2 resources on other servers")));
$check('runner config disabled on the source', GithubRunnerConfig::where('server_id', $server?->id)->value('is_enabled') === false);
$check('runner execution cancelled on the source', GithubRunnerExecution::where('runner_name', 'coolify-runner-fake')->value('status')?->value === 'cancelled');
$record = ManagedDnsRecord::where('name', 'whoami2.example.test')->first();
$check('source keeps the DNS record for a take back, not owned', $record !== null && $record->owned === false);
$check('source keeps the tunnel SSH DNS record for a take back, not owned', ManagedDnsRecord::where('name', 'ssh-debian.example.test')->value('owned') === false);
$check('precheck NOTE about the system-wide GitHub App', $messages->contains(fn ($m) => str_starts_with($m, "NOTE: the system-wide GitHub App 'fake-system-wide-app'")));
$checklist = implode("\n", (array) ($migration['checklist'] ?? []));
$check('checklist: GitHub App URLs', str_contains($checklist, 'GitHub App fake-gh-app'));
$check('checklist: enable GitHub runners', str_contains($checklist, 'enable GitHub runners'));
echo "--- warnings\n".implode("\n", (array) ($migration['warnings'] ?? []))."\n--- checklist\n{$checklist}\n";
