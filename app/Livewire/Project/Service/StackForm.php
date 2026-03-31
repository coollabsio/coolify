<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class StackForm extends Component
{
    public Service $service;

    public Collection $fields;

    protected $listeners = ['saveCompose'];

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public string $dockerComposeRaw;

    public ?string $dockerCompose = null;

    public ?bool $connectToDockerNetwork = null;

    public string $assetActionOutput = '';

    public bool $isRunningAssetAction = false;

    public array $githubBranches = [];

    protected function rules(): array
    {
        $baseRules = [
            'dockerComposeRaw' => 'required',
            'dockerCompose' => 'nullable',
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'connectToDockerNetwork' => 'nullable',
        ];

        // Add dynamic field rules
        foreach ($this->fields ?? collect() as $key => $field) {
            $rules = data_get($field, 'rules', 'nullable');
            $baseRules["fields.$key.value"] = $rules;
        }

        return $baseRules;
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'dockerComposeRaw.required' => 'The Docker Compose Raw field is required.',
                'dockerCompose.required' => 'The Docker Compose field is required.',
            ]
        );
    }

    public $validationAttributes = [];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->service->name = $this->name;
            $this->service->description = $this->description;
            $this->service->docker_compose_raw = $this->dockerComposeRaw;
            $this->service->docker_compose = $this->dockerCompose;
            $this->service->connect_to_docker_network = $this->connectToDockerNetwork;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->service->name;
            $this->description = $this->service->description;
            $this->dockerComposeRaw = $this->service->docker_compose_raw;
            $this->dockerCompose = $this->service->docker_compose;
            $this->connectToDockerNetwork = $this->service->connect_to_docker_network;
        }
    }

    public function mount()
    {
        $this->syncData(false);
        $this->fields = collect([]);
        $extraFields = $this->service->extraFields();
        foreach ($extraFields as $serviceName => $fields) {
            foreach ($fields as $fieldKey => $field) {
                $key = data_get($field, 'key');
                $value = data_get($field, 'value');
                $rules = data_get($field, 'rules', 'nullable');
                $isPassword = data_get($field, 'isPassword', false);
                $customHelper = data_get($field, 'customHelper', false);
                $this->fields->put($key, [
                    'serviceName' => $serviceName,
                    'key' => $key,
                    'name' => $fieldKey,
                    'value' => $value,
                    'isPassword' => $isPassword,
                    'rules' => $rules,
                    'customHelper' => $customHelper,
                ]);

                $this->validationAttributes["fields.$key.value"] = $fieldKey;
            }
        }
        $this->fields = $this->fields->groupBy('serviceName')->map(function ($group) {
            return $group->sortBy(function ($field) {
                return data_get($field, 'isPassword') ? 1 : 0;
            })->mapWithKeys(function ($field) {
                return [$field['key'] => $field];
            });
        })->flatMap(function ($group) {
            return $group;
        });

        if (! $this->isLaravelGitHubStack()) {
            return;
        }

        // Ensure GitHub repository URL is always available as a configurable field
        // for Laravel GitHub-based templates.
        if (! $this->fields->has('SERVICE_GITHUB_REPO_URL')) {
            $githubRepoUrl = $this->service->environment_variables()
                ->where('key', 'SERVICE_GITHUB_REPO_URL')
                ->first();

            $this->fields->put('SERVICE_GITHUB_REPO_URL', [
                'serviceName' => 'SERVICE_GITHUB_REPO_URL',
                'key' => 'SERVICE_GITHUB_REPO_URL',
                'name' => 'GitHub Repo URL',
                'value' => data_get($githubRepoUrl, 'value', ''),
                'isPassword' => false,
                'rules' => 'required|url',
                'customHelper' => 'Public repository URL used to clone your Laravel project.',
            ]);
            $this->validationAttributes['fields.SERVICE_GITHUB_REPO_URL.value'] = 'GitHub Repo URL';
        }
        if ($this->isLaravelRootkitStack() && ! $this->fields->has('SERVICE_GITHUB_BRANCH')) {
            $githubBranch = $this->service->environment_variables()
                ->where('key', 'SERVICE_GITHUB_BRANCH')
                ->first();

            $this->fields->put('SERVICE_GITHUB_BRANCH', [
                'serviceName' => 'SERVICE_GITHUB_BRANCH',
                'key' => 'SERVICE_GITHUB_BRANCH',
                'name' => 'Git Branch',
                'value' => data_get($githubBranch, 'value', 'main'),
                'isPassword' => false,
                'rules' => 'required|string|max:120',
                'customHelper' => 'Git branch to deploy from the configured repository.',
            ]);
            $this->validationAttributes['fields.SERVICE_GITHUB_BRANCH.value'] = 'Git Branch';
        }
        if ($this->isLaravelRootkitStack() && ! $this->fields->has('SERVICE_GITHUB_TOKEN')) {
            $githubToken = $this->service->environment_variables()
                ->where('key', 'SERVICE_GITHUB_TOKEN')
                ->first();

            $this->fields->put('SERVICE_GITHUB_TOKEN', [
                'serviceName' => 'SERVICE_GITHUB_TOKEN',
                'key' => 'SERVICE_GITHUB_TOKEN',
                'name' => 'GitHub Token',
                'value' => data_get($githubToken, 'value', ''),
                'isPassword' => true,
                'rules' => 'nullable|string|max:500',
                'customHelper' => 'Optional token for private repositories. Used for branch detection and authenticated git fetch.',
            ]);
            $this->validationAttributes['fields.SERVICE_GITHUB_TOKEN.value'] = 'GitHub Token';
        }

        if (! $this->fields->has('SERVICE_PHP_VERSION')) {
            $phpVersion = $this->service->environment_variables()
                ->where('key', 'SERVICE_PHP_VERSION')
                ->first();

            $this->fields->put('SERVICE_PHP_VERSION', [
                'serviceName' => 'SERVICE_PHP_VERSION',
                'key' => 'SERVICE_PHP_VERSION',
                'name' => 'PHP Version',
                'value' => data_get($phpVersion, 'value', '8.3'),
                'isPassword' => false,
                'rules' => 'required|in:7.4,8.1,8.2,8.3,8.4',
                'customHelper' => 'PHP runtime version used by the Laravel container.',
            ]);
            $this->validationAttributes['fields.SERVICE_PHP_VERSION.value'] = 'PHP Version';
        }

        $this->setFieldValueIfPresent('SERVICE_URL_LARAVEL', '');
        $this->loadGithubBranches();
    }

    public function isLaravelGitHubStack(): bool
    {
        $raw = (string) ($this->dockerComposeRaw ?? $this->service->docker_compose_raw ?? '');

        return str_contains($raw, 'SERVICE_GITHUB_REPO_URL') || str_contains($raw, 'SERVICE_PHP_VERSION');
    }

    public function isLaravelRootkitStack(): bool
    {
        $raw = (string) ($this->dockerComposeRaw ?? $this->service->docker_compose_raw ?? '');

        return str_contains($raw, 'APP_NAME=Laravel RootKit')
            || (str_contains($raw, 'SERVICE_GITHUB_REPO_URL')
                && str_contains($raw, 'SERVICE_DATABASE_LARAVEL')
                && str_contains($raw, 'phpmyadmin'));
    }

    public function saveCompose($raw)
    {
        $this->dockerComposeRaw = $raw;
        $this->submit(notify: true);
    }

    public function saveGithubRepoUrl(): void
    {
        $this->loadGithubBranches();
        $this->submit(notify: false);
        $this->dispatch('success', 'GitHub repository URL saved.');
    }

    public function savePhpVersion(): void
    {
        $this->submit(notify: false);
        $this->dispatch('success', 'PHP version saved.');
    }

    public function saveGithubBranch(): void
    {
        $this->submit(notify: false);
        $this->dispatch('success', 'Git branch saved.');
    }

    public function saveGithubToken(): void
    {
        $this->submit(notify: false);
        $this->dispatch('success', 'GitHub token saved.');
    }

    public function loadGithubBranches(): void
    {
        if (! $this->isLaravelRootkitStack()) {
            return;
        }
        if (! $this->fields->has('SERVICE_GITHUB_REPO_URL')) {
            return;
        }

        $repoUrl = trim((string) data_get($this->fields, 'SERVICE_GITHUB_REPO_URL.value', ''));
        if ($repoUrl === '') {
            $this->githubBranches = [];

            return;
        }

        $ownerRepo = $this->extractGithubOwnerRepo($repoUrl);
        if (! $ownerRepo) {
            $this->githubBranches = [];

            return;
        }

        [$owner, $repo] = $ownerRepo;
        $githubToken = trim((string) data_get($this->fields, 'SERVICE_GITHUB_TOKEN.value', ''));
        $request = Http::timeout(20)
            ->retry(2, 250, throw: false)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Coolify-Laravel-RootKit',
            ]);
        if ($githubToken !== '') {
            $request = $request->withToken($githubToken);
        }
        $response = $request
            ->get("https://api.github.com/repos/{$owner}/{$repo}/branches", [
                'per_page' => 100,
            ]);

        $branches = [];
        if ($response->successful()) {
            $branches = collect($response->json())
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && $name !== '')
                ->unique()
                ->values()
                ->all();
        }
        if ($branches === []) {
            $branches = $this->loadGithubBranchesFromRemoteGit($repoUrl, $githubToken);
        }

        if ($branches === []) {
            $this->githubBranches = [];
            $this->dispatch('warning', 'No se pudieron detectar ramas. Revisa URL, permisos o rate limit de GitHub.');

            return;
        }

        $priority = ['main', 'master', 'develop', 'dev'];
        usort($branches, function (string $a, string $b) use ($priority): int {
            $indexA = array_search($a, $priority, true);
            $indexB = array_search($b, $priority, true);

            if ($indexA !== false && $indexB !== false) {
                return $indexA <=> $indexB;
            }
            if ($indexA !== false) {
                return -1;
            }
            if ($indexB !== false) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        $this->githubBranches = $branches;

        $selectedBranch = trim((string) data_get($this->fields, 'SERVICE_GITHUB_BRANCH.value', ''));
        if (! in_array($selectedBranch, $this->githubBranches, true)) {
            $this->setFieldValueIfPresent('SERVICE_GITHUB_BRANCH', $this->githubBranches[0]);
        }
        $this->dispatch('success', 'Ramas detectadas correctamente.');
    }

    public function saveServiceUrl(): void
    {
        $this->submit(notify: false);
    }

    public function rebuildFrontendAssets(): void
    {
        if (! $this->isLaravelRootkitStack()) {
            $this->dispatch('error', 'This action is only available for Laravel RootKit services.');

            return;
        }

        $this->runFrontendAssetCommand(
            "cd /var/www/html && if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi && npm run build"
        );
    }

    public function verifyFrontendAssets(): void
    {
        if (! $this->isLaravelRootkitStack()) {
            $this->dispatch('error', 'This action is only available for Laravel RootKit services.');

            return;
        }

        $this->runFrontendAssetCommand(
            "cd /var/www/html && test -f public/build/manifest.json && ls -la public/build && echo 'Vite assets OK'"
        );
    }

    public function deployLaravelChanges(): void
    {
        if (! $this->isLaravelRootkitStack()) {
            $this->dispatch('error', 'This action is only available for Laravel RootKit services.');

            return;
        }

        $branch = trim((string) data_get($this->fields, 'SERVICE_GITHUB_BRANCH.value', 'main'));
        if ($branch === '') {
            $branch = 'main';
        }
        $repoUrl = trim((string) data_get($this->fields, 'SERVICE_GITHUB_REPO_URL.value', ''));
        if ($repoUrl === '') {
            $this->dispatch('error', 'GitHub repository URL is required for Deploy cambios.');

            return;
        }
        $githubToken = trim((string) data_get($this->fields, 'SERVICE_GITHUB_TOKEN.value', ''));
        $deployRepoUrl = $this->buildGithubUrlWithToken($repoUrl, $githubToken);

        $branchRef = escapeshellarg("origin/{$branch}");

        $command = "cd /var/www/html"
            ." && if [ ! -d .git ]; then echo 'ERROR: Repository is not initialized in /var/www/html (.git missing).'; exit 1; fi"
            ." && CURRENT_HEAD=\"\$(git rev-parse HEAD 2>/dev/null || true)\""
            ." && git remote set-url origin ".escapeshellarg($deployRepoUrl)
            ." && if ! git fetch --quiet origin ".escapeshellarg($branch)."; then echo 'ERROR: git fetch failed'; exit 1; fi"
            ." && TARGET_HEAD=\"\$(git rev-parse {$branchRef} 2>/dev/null || true)\""
            ." && if [ -z \"\$TARGET_HEAD\" ]; then echo 'ERROR: Unable to resolve target commit from remote branch.'; exit 1; fi"
            ." && if [ -n \"\$CURRENT_HEAD\" ] && [ \"\$CURRENT_HEAD\" = \"\$TARGET_HEAD\" ]; then echo 'No new commits to deploy.'; else echo 'New commits deployed:'; if [ -n \"\$CURRENT_HEAD\" ]; then git log --reverse --format='%h %s (%an)' \"\$CURRENT_HEAD..\$TARGET_HEAD\"; else git log --reverse --format='%h %s (%an)' -n 10 \"\$TARGET_HEAD\"; fi; fi"
            ." && if ! git checkout -B ".escapeshellarg($branch)." {$branchRef}; then echo 'ERROR: git checkout failed'; exit 1; fi"
            ." && if [ -f composer.json ]; then if ! composer install --no-interaction --prefer-dist --optimize-autoloader >/tmp/coolify-composer-install.log 2>&1; then echo 'ERROR: composer install failed'; echo 'Failed at: composer install'; sed -n '1,220p' /tmp/coolify-composer-install.log; exit 1; fi; fi"
            ." && if [ -f package.json ]; then if [ -f package-lock.json ]; then NPM_INSTALL_CMD='npm ci --no-audit --no-fund'; else NPM_INSTALL_CMD='npm install --no-audit --no-fund'; fi; if ! sh -lc \"\$NPM_INSTALL_CMD && npm run build\" >/tmp/coolify-npm-build.log 2>&1; then echo 'ERROR: frontend build failed'; echo 'Failed at: npm install/build'; sed -n '1,220p' /tmp/coolify-npm-build.log; exit 1; fi; fi"
            ." && if [ -f .env ]; then if grep -Eq '^ASSET_URL=' .env; then sed -i 's|^ASSET_URL=.*|ASSET_URL=|' .env; else echo 'ASSET_URL=' >> .env; fi; fi"
            ." && if [ -f artisan ]; then php artisan optimize:clear >/tmp/coolify-artisan-clear.log 2>&1 || true; fi"
            ." && echo \"Deploy completed successfully at commit \$(git rev-parse --short HEAD)\"";

        $this->runFrontendAssetCommand($command);
    }

    public function runLaravelMigrations(): void
    {
        if (! $this->isLaravelRootkitStack()) {
            $this->dispatch('error', 'This action is only available for Laravel RootKit services.');

            return;
        }

        $this->runFrontendAssetCommand(
            "cd /var/www/html && if [ -f artisan ]; then "
            ."php artisan optimize:clear || true; "
            ."php artisan config:clear || true; "
            ."echo 'Laravel migration context:'; "
            .'echo "APP_ENV=${APP_ENV:-}"; '
            .'echo "DB_CONNECTION=${DB_CONNECTION:-}"; '
            .'echo "DB_HOST=${DB_HOST:-}"; '
            .'echo "DB_PORT=${DB_PORT:-}"; '
            .'echo "DB_DATABASE=${DB_DATABASE:-}"; '
            .'echo "DB_USERNAME=${DB_USERNAME:-}"; '
            .'echo "CACHE_STORE=${CACHE_STORE:-}"; '
            .'echo "QUEUE_CONNECTION=${QUEUE_CONNECTION:-}"; '
            ."echo 'Migration status before run:'; "
            ."php artisan migrate:status --no-ansi || true; "
            ."MIGRATION_OUTPUT_FILE=/tmp/coolify-migrate-output.log; "
            ."if php artisan migrate --force --no-ansi >\"\$MIGRATION_OUTPUT_FILE\" 2>&1; then "
            ."if [ -s \"\$MIGRATION_OUTPUT_FILE\" ]; then echo 'Migrations completed with output:'; sed -n '1,220p' \"\$MIGRATION_OUTPUT_FILE\"; else echo 'Migrations completed successfully with no warnings.'; fi; "
            ."else echo 'ERROR: migrations failed'; echo 'Failed at: php artisan migrate --force'; sed -n '1,220p' \"\$MIGRATION_OUTPUT_FILE\"; exit 1; fi; "
            ."else echo 'artisan file not found'; exit 1; fi"
        );
    }

    public function runLaravelMaintenanceCommand(string $commandName): void
    {
        if (! $this->isLaravelRootkitStack()) {
            $this->dispatch('error', 'This action is only available for Laravel RootKit services.');

            return;
        }

        $commands = [
            'clear-config-and-cache' => [
                'label' => 'config:clear && cache:clear',
                'command' => 'php artisan config:clear --no-ansi && php artisan cache:clear --no-ansi',
            ],
            'config-cache' => [
                'label' => 'config:cache',
                'command' => 'php artisan config:cache --no-ansi',
            ],
            'queue-restart' => [
                'label' => 'queue:restart',
                'command' => 'php artisan queue:restart --no-ansi',
            ],
        ];

        $selectedCommand = $commands[$commandName] ?? null;

        if (! is_array($selectedCommand)) {
            $this->dispatch('error', 'Unknown Laravel maintenance command.');

            return;
        }

        $this->runFrontendAssetCommand(
            "cd /var/www/html && if [ -f artisan ]; then "
            ."echo 'Running Laravel maintenance command:'; "
            ."echo ".escapeshellarg($selectedCommand['label'])."; "
            ."MAINTENANCE_OUTPUT_FILE=/tmp/coolify-maintenance-command.log; "
            ."if sh -lc ".escapeshellarg($selectedCommand['command'])." >\"\$MAINTENANCE_OUTPUT_FILE\" 2>&1; then "
            ."if [ -s \"\$MAINTENANCE_OUTPUT_FILE\" ]; then sed -n '1,220p' \"\$MAINTENANCE_OUTPUT_FILE\"; else echo 'Command completed successfully with no output.'; fi; "
            ."else echo 'ERROR: maintenance command failed'; echo 'Failed at: ".addslashes($selectedCommand['label'])."'; sed -n '1,220p' \"\$MAINTENANCE_OUTPUT_FILE\"; exit 1; fi; "
            ."else echo 'artisan file not found'; exit 1; fi"
        );
    }

    public function instantSave()
    {
        $this->syncData(true);
        $this->service->save();
        $this->dispatch('success', 'Service settings saved.');
    }

    public function submit($notify = true)
    {
        try {
            $this->setFieldValueIfPresent('SERVICE_URL_LARAVEL', '');
            $this->validate();
            $this->syncLaravelDatabaseVariable();
            $this->syncData(true);

            // Validate for command injection BEFORE any database operations
            validateDockerComposeForInjection($this->service->docker_compose_raw);

            // Use transaction to ensure atomicity - if parse fails, save is rolled back
            DB::transaction(function () {
                $this->service->save();
                $this->service->saveExtraFields($this->fields);
                $this->service->parse();
            });
            // Refresh and write files after a successful commit
            $this->service->refresh();
            $this->service->saveComposeConfigs();

            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
            $notify && $this->dispatch('success', 'Service saved.');
        } catch (\Throwable $e) {
            // On error, refresh from database to restore clean state
            $this->service->refresh();
            $this->syncData(false);

            return handleError($e, $this);
        } finally {
            if (is_null($this->service->config_hash)) {
                $this->service->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    private function syncLaravelDatabaseVariable(): void
    {
        $databaseField = $this->fields->get('MYSQL_DATABASE')
            ?? $this->fields->get('SERVICE_DATABASE_MARIADB');

        $databaseName = (string) data_get($databaseField, 'value', '');
        if ($databaseName === '') {
            return;
        }

        $this->fields->put('SERVICE_DATABASE_LARAVEL', [
            'serviceName' => 'SERVICE_DATABASE_LARAVEL',
            'key' => 'SERVICE_DATABASE_LARAVEL',
            'name' => 'Laravel Database Name',
            'value' => $databaseName,
            'isPassword' => false,
            'rules' => 'nullable|string',
            'customHelper' => 'Auto-synced from MariaDB Database Name.',
        ]);
    }

    private function setFieldValueIfPresent(string $key, string $value): void
    {
        $field = $this->fields->get($key, []);
        if (! is_array($field) || $field === []) {
            return;
        }

        $field['value'] = $value;
        $this->fields->put($key, $field);
    }

    private function runFrontendAssetCommand(string $shellCommand): void
    {
        $this->isRunningAssetAction = true;
        $this->assetActionOutput = '';

        try {
            $context = $this->getLaravelContainerContext();
            if (! $context) {
                $this->dispatch('error', 'Laravel container not found or not running.');

                return;
            }

            $server = $context['server'];
            $escapedContainer = $context['escapedContainer'];
            $command = "docker exec {$escapedContainer} sh -lc ".escapeshellarg($shellCommand);
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }

            $this->assetActionOutput = (string) (instant_remote_process([$command], $server, false) ?? '');
            $this->dispatch('success', 'Asset command executed.');
        } catch (\Throwable $e) {
            $this->assetActionOutput = $e->getMessage();
            $this->dispatch('error', 'Asset command failed: '.$e->getMessage());
        } finally {
            $this->isRunningAssetAction = false;
        }
    }

    private function getLaravelContainerContext(): ?array
    {
        $application = $this->service->applications
            ->first(fn ($app) => str($app->name)->lower()->contains('laravel')
                && str($app->status)->contains('running'));

        if (! $application) {
            $application = $this->service->applications
                ->first(fn ($app) => str($app->status)->contains('running'));
        }

        if (! $application) {
            return null;
        }

        $server = $application->service->server;
        $containerName = $application->name.'-'.$this->service->uuid;

        return [
            'server' => $server,
            'escapedContainer' => escapeshellarg($containerName),
        ];
    }

    private function extractGithubOwnerRepo(string $repoUrl): ?array
    {
        $url = trim($repoUrl);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'git@github.com:')) {
            $path = substr($url, strlen('git@github.com:'));
        } else {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.$url;
            }
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== 'github.com' && $host !== 'www.github.com') {
                return null;
            }
            $path = trim((string) ($parts['path'] ?? ''), '/');
        }

        $path = preg_replace('/\.git$/', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) < 2) {
            return null;
        }

        return [(string) $segments[0], (string) $segments[1]];
    }

    private function loadGithubBranchesFromRemoteGit(string $repoUrl, string $githubToken = ''): array
    {
        $server = $this->service->server;
        $url = trim($this->buildGithubUrlWithToken($repoUrl, $githubToken));
        if ($url !== '' && ! str_ends_with($url, '.git')) {
            $url .= '.git';
        }
        if ($url === '') {
            return [];
        }

        $command = "git ls-remote --heads ".escapeshellarg($url)." 2>/dev/null";
        if ($server->isNonRoot()) {
            $command = "sudo {$command}";
        }

        $output = (string) (instant_remote_process([$command], $server, false) ?? '');
        if (trim($output) === '') {
            return [];
        }

        $branches = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (! is_string($line) || $line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $ref = $parts[1] ?? '';
            if (! is_string($ref) || ! str_starts_with($ref, 'refs/heads/')) {
                continue;
            }
            $branch = substr($ref, strlen('refs/heads/'));
            if ($branch !== '') {
                $branches[] = $branch;
            }
        }

        return collect($branches)->unique()->values()->all();
    }

    private function buildGithubUrlWithToken(string $repoUrl, string $githubToken): string
    {
        $url = trim($repoUrl);
        $token = trim($githubToken);
        if ($url === '' || $token === '') {
            return $url;
        }

        if (str_starts_with($url, 'git@github.com:')) {
            $path = substr($url, strlen('git@github.com:'));
            if (! is_string($path) || $path === '') {
                return $url;
            }

            return "https://x-access-token:{$token}@github.com/{$path}";
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'github.com' && $host !== 'www.github.com') {
            return $repoUrl;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return $repoUrl;
        }

        return "https://x-access-token:{$token}@github.com{$path}";
    }

    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
